<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use RuntimeException;
use Throwable;

final class FlightRestClient
{
    private string $accessToken = '';

    public function __construct(private array $config)
    {
        foreach (['base_url', 'username', 'password', 'appkey'] as $key) {
            if (trim((string)($config[$key] ?? '')) === '') {
                throw new RuntimeException(
                    "Vereinsflieger-Konfiguration fehlt: {$key}"
                );
            }
        }

        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP-cURL ist nicht installiert.');
        }
    }

    /**
     * Sendet genau einen Flug an Vereinsflieger.
     *
     * @param array<string,mixed> $payload
     * @return array<string|int,mixed>
     */
    public function addFlight(array $payload): array
    {
        return $this->authenticated(
            static fn(self $client): array => $client->request(
                'POST',
                'flight/add',
                $payload + [
                    'accesstoken' => $client->accessToken,
                ]
            )
        );
    }

    /**
     * Führt eine Aktion innerhalb einer VF-Anmeldung aus.
     *
     * @return array<string|int,mixed>
     */
    private function authenticated(callable $operation): array
    {
        try {
            $this->accessToken = $this->extractToken(
                $this->request('GET', 'auth/accesstoken')
            );

            $password = (string)$this->config['password'];
            $latin1 = function_exists('iconv')
                ? iconv(
                    'UTF-8',
                    'ISO-8859-1//TRANSLIT',
                    $password
                )
                : $password;

            if ($latin1 === false) {
                throw new RuntimeException(
                    'VF-Passwort konnte nicht konvertiert werden.'
                );
            }

            $signin = [
                'accesstoken' => $this->accessToken,
                'username' => (string)$this->config['username'],
                'password' => md5($latin1),
                'appkey' => (string)$this->config['appkey'],
            ];

            foreach (['cid', 'auth_secret'] as $key) {
                $value = trim((string)($this->config[$key] ?? ''));

                if ($value !== '') {
                    $signin[$key] = $value;
                }
            }

            $this->request('POST', 'auth/signin', $signin);

            $result = $operation($this);

            if (!is_array($result)) {
                throw new RuntimeException(
                    'Die VF-Aktion lieferte kein gültiges Ergebnis.'
                );
            }

            return $result;
        } finally {
            $this->signOutQuietly();
        }
    }

    /**
     * Führt einen HTTP-Aufruf gegen die VF-REST-Schnittstelle aus.
     *
     * Bei Fehlern wird der Antworttext beziehungsweise das vollständige
     * JSON in die Fehlermeldung aufgenommen. Zugangsdaten und Access-Token
     * werden dabei nicht protokolliert.
     *
     * @param array<string,mixed> $fields
     * @return array<string|int,mixed>
     */
    private function request(
        string $method,
        string $path,
        array $fields = []
    ): array {
        $method = strtoupper($method);

        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            throw new RuntimeException(
                "Nicht unterstützte HTTP-Methode: {$method}"
            );
        }

        $url = rtrim((string)$this->config['base_url'], '/')
            . '/'
            . ltrim($path, '/');

        if ($method === 'GET' && $fields !== []) {
            $url .= '?'
                . http_build_query(
                    $fields,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                'cURL konnte nicht initialisiert werden.'
            );
        }

        $headers = [
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)(
                $this->config['connect_timeout'] ?? 10
            ),
            CURLOPT_TIMEOUT => (int)(
                $this->config['timeout'] ?? 30
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query(
                $fields,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $options[CURLOPT_HTTPHEADER][] =
                'Content-Type: application/x-www-form-urlencoded';
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException(
                'VF-Verbindungsfehler: ' . $curlError
            );
        }

        $bodyText = $this->removeUtf8Bom((string)$body);
        $decoded = json_decode($bodyText, true);

        if (!is_array($decoded)) {
            $detail = $this->plainResponseDetail($bodyText);

            throw new RuntimeException(
                sprintf(
                    'VF antwortet bei %s %s mit HTTP %d '
                    . 'und ohne gültiges JSON: %s',
                    $method,
                    $path,
                    $httpStatus,
                    $detail
                )
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                sprintf(
                    'VF antwortet bei %s %s mit HTTP %d: %s',
                    $method,
                    $path,
                    $httpStatus,
                    $this->jsonResponseDetail($decoded)
                )
            );
        }

        $vfStatus = isset($decoded['httpstatuscode'])
            ? (int)$decoded['httpstatuscode']
            : 200;

        if (
            $vfStatus >= 400
            || !empty($decoded['error'])
            || !empty($decoded['errormessage'])
        ) {
            throw new RuntimeException(
                sprintf(
                    'VF meldet bei %s %s den Status %d: %s',
                    $method,
                    $path,
                    $vfStatus,
                    $this->jsonResponseDetail($decoded)
                )
            );
        }

        return $decoded;
    }

    /**
     * @param array<string|int,mixed> $payload
     */
    private function extractToken(array $payload): string
    {
        foreach (['accesstoken', 'access_token', 'token'] as $key) {
            $token = trim(
                (string)(
                    $payload[$key]
                    ?? ($payload['data'][$key] ?? '')
                )
            );

            if ($token !== '') {
                return $token;
            }
        }

        throw new RuntimeException(
            'VF-Antwort enthält kein Access-Token.'
        );
    }

    /**
     * Erstellt aus einer JSON-Antwort eine kurze, aussagekräftige
     * Fehlermeldung. Access-Token werden vorsorglich entfernt.
     *
     * @param array<string|int,mixed> $decoded
     */
    private function jsonResponseDetail(array $decoded): string
    {
        $detail = $decoded['error']
            ?? $decoded['errormessage']
            ?? $decoded['message']
            ?? $decoded['result']
            ?? $decoded;

        if (is_array($detail)) {
            $this->removeSensitiveValues($detail);

            $encoded = json_encode(
                $detail,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );

            $detail = $encoded === false ? '' : $encoded;
        }

        $detail = trim((string)$detail);

        if ($detail === '') {
            return '(keine Details)';
        }

        return $this->limitText($detail, 1000);
    }

    /**
     * Entfernt mögliche Zugangsdaten aus einer Fehlerantwort.
     *
     * @param array<string|int,mixed> $values
     */
    private function removeSensitiveValues(array &$values): void
    {
        foreach ($values as $key => &$value) {
            $normalizedKey = strtolower((string)$key);

            if (in_array(
                $normalizedKey,
                [
                    'accesstoken',
                    'access_token',
                    'token',
                    'password',
                    'appkey',
                    'auth_secret',
                ],
                true
            )) {
                $value = '[entfernt]';
                continue;
            }

            if (is_array($value)) {
                $this->removeSensitiveValues($value);
            }
        }

        unset($value);
    }

    private function plainResponseDetail(string $body): string
    {
        $detail = strip_tags($body);
        $detail = preg_replace('/\s+/u', ' ', $detail) ?? $detail;
        $detail = trim($detail);

        return $detail === ''
            ? '(leere Antwort)'
            : $this->limitText($detail, 1000);
    }

    private function limitText(string $value, int $maximumLength): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maximumLength, 'UTF-8');
        }

        return substr($value, 0, $maximumLength);
    }

    private function signOutQuietly(): void
    {
        if ($this->accessToken === '') {
            return;
        }

        try {
            $this->request(
                'DELETE',
                'auth/signout/' . rawurlencode($this->accessToken)
            );
        } catch (Throwable $error) {
            error_log(
                'VF signout failed: ' . $error->getMessage()
            );
        } finally {
            $this->accessToken = '';
        }
    }

    private function removeUtf8Bom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }
}
