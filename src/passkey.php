<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/webauthn_config.php';

use Cose\Algorithms;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

function passkey_serializer(): \Symfony\Component\Serializer\SerializerInterface
{
    static $serializer = null;
    if ($serializer !== null) return $serializer;
    $manager = AttestationStatementSupportManager::create();
    $manager->add(NoneAttestationStatementSupport::create());
    $serializer = (new WebauthnSerializerFactory($manager))->create();
    return $serializer;
}

function passkey_json_serialize(object $value): string
{
    return passkey_serializer()->serialize($value, 'json', [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
    ]);
}

function passkey_creation_options(array $user): PublicKeyCredentialCreationOptions
{
    $cfg = webauthn_config();
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) throw new RuntimeException('Ungültiger Benutzer.');
    $displayName = trim((string)($user['display_name'] ?? ''));
    if ($displayName === '') throw new RuntimeException('Beim Benutzer fehlt der Anzeigename.');

    $userHandle = hash('sha256', 'lszj-user:' . $userId, true);
    $rp = PublicKeyCredentialRpEntity::create((string)$cfg['rp_name'], (string)$cfg['rp_id'], null);
    $userName = trim((string)($user['email'] ?? ''));
    if ($userName === '') $userName = 'user-' . $userId;
    $userEntity = PublicKeyCredentialUserEntity::create($userName, $userHandle, $displayName, null);

    $excludeCredentials = [];
    $stmt = db()->prepare('SELECT credential_id FROM user_passkeys WHERE user_id=:user_id AND revoked_at IS NULL');
    $stmt->execute(['user_id'=>$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $credentialId) {
        $excludeCredentials[] = PublicKeyCredentialDescriptor::create('public-key', (string)$credentialId);
    }

    $parameters = [
        PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
        PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
    ];

    return PublicKeyCredentialCreationOptions::create(
        $rp,
        $userEntity,
        random_bytes(32),
        $parameters,
        excludeCredentials: $excludeCredentials
    );
}

function passkey_store_creation_challenge(PublicKeyCredentialCreationOptions $options, int $userId): void
{
    session_start_if_needed();
    $_SESSION['webauthn_registration'] = [
        'user_id'=>$userId,
        'options_json'=>passkey_json_serialize($options),
        'created_at'=>time(),
    ];
}

function passkey_finish_registration(string $credentialJson, string $deviceName): int
{
    session_start_if_needed();
    $state = $_SESSION['webauthn_registration'] ?? null;
    unset($_SESSION['webauthn_registration']);
    if (!is_array($state)) throw new RuntimeException('Passkey-Anfrage fehlt oder ist abgelaufen.');
    $createdAt = (int)($state['created_at'] ?? 0);
    if ($createdAt <= 0 || time() - $createdAt > 300) throw new RuntimeException('Passkey-Anfrage ist abgelaufen.');

    $currentUser = auth_require_login();
    $currentUserId = (int)($currentUser['id'] ?? 0);
    if ((int)($state['user_id'] ?? 0) !== $currentUserId) {
        throw new RuntimeException('Passkey-Anfrage gehört zu einem anderen Benutzer.');
    }

    $optionsJson = (string)($state['options_json'] ?? '');
    if ($optionsJson === '') throw new RuntimeException('Gespeicherte Passkey-Optionen fehlen.');

    $serializer = passkey_serializer();
    $options = $serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
    $credential = $serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
    if (!$options instanceof PublicKeyCredentialCreationOptions) throw new RuntimeException('Gespeicherte Passkey-Optionen sind ungültig.');
    if (!$credential instanceof PublicKeyCredential) throw new RuntimeException('WebAuthn-Credential ist ungültig.');
    if (!$credential->response instanceof AuthenticatorAttestationResponse) {
        throw new RuntimeException('WebAuthn-Antwort ist keine Registrierungsantwort.');
    }

    $cfg = webauthn_config();
    $ceremonyFactory = new CeremonyStepManagerFactory();
    $ceremonyFactory->setAllowedOrigins((array)$cfg['allowed_origins']);
    if ((string)$cfg['rp_id'] === 'localhost') {
        $ceremonyFactory->setSecuredRelyingPartyId(['localhost']);
    }

    $validator = AuthenticatorAttestationResponseValidator::create(
        $ceremonyFactory->creationCeremony()
    );

    /** @var PublicKeyCredentialSource $credentialSource */
    $credentialSource = $validator->check(
        $credential->response,
        $options,
        (string)$cfg['rp_id']
    );

    $credentialId = $credentialSource->publicKeyCredentialId;
    $userHandle = $credentialSource->userHandle;
    $signCount = (int)$credentialSource->counter;
    $sourceJson = passkey_json_serialize($credentialSource);
    if ($credentialId === '') throw new RuntimeException('Die validierte Credential-ID fehlt.');
    if ($userHandle === null || $userHandle === '') {
        $userHandle = hash('sha256', 'lszj-user:' . $currentUserId, true);
    }

    $deviceName = trim($deviceName);
    if ($deviceName === '') $deviceName = 'Passkey';
    if (mb_strlen($deviceName) > 255) $deviceName = mb_substr($deviceName, 0, 255);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $duplicate = $pdo->prepare('SELECT id FROM user_passkeys WHERE credential_id=:credential_id LIMIT 1');
        $duplicate->bindValue(':credential_id', $credentialId, PDO::PARAM_LOB);
        $duplicate->execute();
        if ($duplicate->fetchColumn() !== false) throw new RuntimeException('Dieser Passkey ist bereits registriert.');

        $insert = $pdo->prepare(
            'INSERT INTO user_passkeys (user_id,credential_id,public_key,user_handle,sign_count,device_name)
             VALUES (:user_id,:credential_id,:public_key,:user_handle,:sign_count,:device_name)'
        );
        $insert->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        $insert->bindValue(':credential_id', $credentialId, PDO::PARAM_LOB);
        $insert->bindValue(':public_key', $sourceJson, PDO::PARAM_STR);
        $insert->bindValue(':user_handle', $userHandle, PDO::PARAM_LOB);
        $insert->bindValue(':sign_count', $signCount, PDO::PARAM_INT);
        $insert->bindValue(':device_name', $deviceName, PDO::PARAM_STR);
        $insert->execute();
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
