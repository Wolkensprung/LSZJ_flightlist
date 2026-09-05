<?php
declare(strict_types=1);

namespace LSZJ\Vereinsflieger;

use RuntimeException;

final class RestClient
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
     * @return list<array<string,mixed>>
     */
    public function listUsers(): array
    {
        try {
            $tokenResponse = $this->request(
                'GET',
                'auth/accesstoken'
            );

            $this->accessToken = $this->extractToken($tokenResponse);

            $password = (string)$this->config['password'];
            $passwordLatin1 = function_exists('iconv')
                ? iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $password)
                : $password;

            if ($passwordLatin1 === false) {
                throw new RuntimeException(
                    'VF-Passwort konnte nicht nach ISO-8859-1 konvertiert werden.'
                );
            }

            $signInFields = [
                'accesstoken' => $this->accessToken,
                'username' => (string)$this->config['username'],
                'password' => md5($passwordLatin1),
                'appkey' => (string)$this->config['appkey'],
            ];

            foreach (['cid', 'auth_secret'] as $optionalKey) {
                $optionalValue = trim(
                    (string)($this->config[$optionalKey] ?? '')
                );

                if ($optionalValue !== '') {
                    $signInFields[$optionalKey] = $optionalValue;
                }
            }

            $this->request(
                'POST',
                'auth/signin',
                $signInFields
            );

            $usersResponse = $this->request(
                'POST',
                'user/list',
                ['accesstoken' => $this->accessToken]
            );

            return $this->extractRows($usersResponse);
        } finally {
            $this->signOutQuietly();
        }
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function request(
        string $method,
        string $path,
        array $fields = []
    ): array {
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
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query(
                $fields,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        if ($body === false || $curlErrorNumber !== 0) {
            throw new RuntimeException(
                'VF-Verbindungsfehler: ' . $curlError
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $detail = trim(strip_tags((string)$body));
            $detail = preg_replace('/\s+/', ' ', $detail) ?? '';
            $detail = function_exists('mb_substr')
                ? mb_substr($detail, 0, 300)
                : substr($detail, 0, 300);

            $message = sprintf(
                'VF antwortet bei %s %s mit HTTP %d.',
                $method,
                $path,
                $httpStatus
            );

            if ($detail !== '') {
                $message .= ' VF-Antwort: ' . $detail;
            }

            throw new RuntimeException($message);
        }

        $decoded = json_decode(
            $this->removeUtf8Bom((string)$body),
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'VF-Antwort ist kein gültiges JSON.'
            );
        }

        $apiError = $decoded['error']
            ?? $decoded['errormessage']
            ?? null;

        if ($apiError) {
            throw new RuntimeException(
                'VF-Fehler: ' . (string)$apiError
            );
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractToken(array $payload): string
    {
        foreach (['accesstoken', 'access_token', 'token'] as $key) {
            $value = trim(
                (string)(
                    $payload[$key]
                    ?? ($payload['data'][$key] ?? '')
                )
            );

            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException(
            'VF-Antwort enthält kein Access-Token.'
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function extractRows(array $payload): array
    {
        $candidates = [
            $payload,
            $payload['data'] ?? null,
            $payload['users'] ?? null,
            $payload['items'] ?? null,
            $payload['result'] ?? null,
            $payload['response'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $rows = $this->rowsFromCandidate($candidate);

            if ($rows !== null) {
                return $rows;
            }
        }

        throw new RuntimeException(
            'VF-Personenliste hat ein unbekanntes Antwortformat. '
            . 'JSON-Struktur: '
            . json_encode(
                $this->describeStructure($payload),
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private function rowsFromCandidate(mixed $candidate): ?array
    {
        if (!is_array($candidate)) {
            return null;
        }

        if ($candidate === []) {
            return [];
        }

        if (
            array_is_list($candidate)
            && is_array($candidate[0] ?? null)
        ) {
            return $candidate;
        }

        foreach (
            [
                'users',
                'items',
                'data',
                'result',
                'rows',
                'members',
                'user',
            ] as $key
        ) {
            if (!isset($candidate[$key])) {
                continue;
            }

            $nestedRows = $this->rowsFromCandidate(
                $candidate[$key]
            );

            if ($nestedRows !== null) {
                return $nestedRows;
            }
        }

        /*
         * Einige APIs liefern Datensätze als Objekt, dessen Schlüssel
         * beispielsweise Benutzer-IDs sind. In diesem Fall werden die
         * Objektwerte als Zeilen verwendet.
         */
        $values = array_values($candidate);

        if (
            $values !== []
            && $this->allElementsAreArrays($values)
        ) {
            return $values;
        }

        return null;
    }

    /**
     * @param array<mixed> $values
     */
    private function allElementsAreArrays(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Beschreibt nur Schlüssel und Datentypen.
     * Personen- und Zugangsdaten werden nicht ausgegeben.
     *
     * @return array<string,mixed>
     */
    private function describeStructure(
        mixed $value,
        int $depth = 0
    ): array {
        if ($depth >= 3) {
            return [
                'type' => get_debug_type($value),
            ];
        }

        if (!is_array($value)) {
            return [
                'type' => get_debug_type($value),
            ];
        }

        $description = [
            'type' => array_is_list($value) ? 'list' : 'object',
            'count' => count($value),
            'keys' => array_slice(
                array_map('strval', array_keys($value)),
                0,
                30
            ),
        ];

        if ($value === []) {
            return $description;
        }

        if (array_is_list($value)) {
            $description['first_item'] = $this->describeStructure(
                $value[0],
                $depth + 1
            );

            return $description;
        }

        $children = [];

        foreach (
            array_slice($value, 0, 10, true)
            as $key => $child
        ) {
            $children[(string)$key] = $this->describeStructure(
                $child,
                $depth + 1
            );
        }

        $description['children'] = $children;

        return $description;
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
        } catch (\Throwable $error) {
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
