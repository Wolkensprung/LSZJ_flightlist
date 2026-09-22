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
                throw new RuntimeException("Vereinsflieger-Konfiguration fehlt: {$key}");
            }
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP-cURL ist nicht installiert.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function listUsers(): array
    {
        try {
            $this->accessToken = $this->extractToken(
                $this->request('GET', 'auth/accesstoken')
            );

            $password = (string)$this->config['password'];
            $latin1 = function_exists('iconv')
                ? iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $password)
                : $password;
            if ($latin1 === false) {
                throw new RuntimeException('VF-Passwort konnte nicht nach ISO-8859-1 konvertiert werden.');
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
            $response = $this->request(
                'POST',
                'user/list',
                ['accesstoken' => $this->accessToken]
            );
            return $this->extractRows($response);
        } finally {
            $this->signOutQuietly();
        }
    }

    /** @param array<string,mixed> $fields @return array<string|int,mixed> */
    private function request(string $method, string $path, array $fields = []): array
    {
        $method = strtoupper($method);
        $url = rtrim((string)$this->config['base_url'], '/') . '/' . ltrim($path, '/');
        if ($method === 'GET' && $fields !== []) {
            $url .= '?' . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('cURL konnte nicht initialisiert werden.');
        }

        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int)($this->config['connect_timeout'] ?? 10),
            CURLOPT_TIMEOUT => (int)($this->config['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } elseif ($method !== 'GET') {
            curl_close($curl);
            throw new RuntimeException("Nicht unterstützte HTTP-Methode: {$method}");
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('VF-Verbindungsfehler: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('VF antwortet bei %s %s mit HTTP %d.', $method, $path, $status));
        }

        $json = $this->removeUtf8Bom((string)$body);
        if (!mb_check_encoding($json, 'UTF-8')) {
            throw new RuntimeException('VF-Antwort ist kein gueltiges UTF-8. Import abgebrochen.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('VF-Antwort ist kein gültiges JSON.');
        }
        $apiError = $decoded['error'] ?? $decoded['errormessage'] ?? null;
        if ($apiError !== null && $apiError !== '') {
            throw new RuntimeException('VF-Fehler: ' . (string)$apiError);
        }
        $vfStatus = isset($decoded['httpstatuscode']) ? (int)$decoded['httpstatuscode'] : null;
        if ($vfStatus !== null && $vfStatus >= 400) {
            throw new RuntimeException(sprintf('VF meldet bei %s %s den Status %d.', $method, $path, $vfStatus));
        }
        return $decoded;
    }

    /** @param array<string|int,mixed> $payload */
    private function extractToken(array $payload): string
    {
        foreach (['accesstoken', 'access_token', 'token'] as $key) {
            $value = trim((string)($payload[$key] ?? ($payload['data'][$key] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }
        throw new RuntimeException('VF-Antwort enthält kein Access-Token.');
    }

    /** @param array<string|int,mixed> $payload @return list<array<string,mixed>> */
    private function extractRows(array $payload): array
    {
        $rows = [];
        foreach ($payload as $key => $value) {
            if (!(is_int($key) || ctype_digit((string)$key))) {
                continue; // z.B. httpstatuscode
            }
            if (!is_array($value)) {
                throw new RuntimeException("VF-Personendatensatz {$key} hat ein ungültiges Format.");
            }
            if (trim((string)($value['uid'] ?? '')) === '') {
                throw new RuntimeException("VF-Personendatensatz {$key} enthält keine UID.");
            }
            $rows[(int)$key] = $value;
        }
        if ($rows === []) {
            throw new RuntimeException('VF lieferte keine Personendatensätze. Es wurden keine Daten geändert.');
        }
        ksort($rows, SORT_NUMERIC);
        return array_values($rows);
    }

    private function signOutQuietly(): void
    {
        if ($this->accessToken === '') {
            return;
        }
        try {
            $this->request('DELETE', 'auth/signout/' . rawurlencode($this->accessToken));
        } catch (\Throwable $error) {
            error_log('VF signout failed: ' . $error->getMessage());
        } finally {
            $this->accessToken = '';
        }
    }

    private function removeUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
