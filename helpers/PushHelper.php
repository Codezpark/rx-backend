<?php

declare(strict_types=1);

/**
 * PushHelper — sends Firebase Cloud Messaging (HTTP v1) notifications using a
 * service-account key. Pure PHP: it signs a JWT with the service account's
 * private key (RS256 via openssl), exchanges it for an OAuth access token, and
 * posts to the FCM v1 endpoint. Everything is best-effort — if the key file is
 * missing or anything fails, it quietly sends nothing (the app still works).
 *
 * The key path comes from Config('fcm.service_account'), defaulting to
 * storage/fcm-service-account.json (kept out of git and out of the web root).
 */
class PushHelper
{
    private static ?array $sa = null;          // [] means "looked, not found"
    private static ?string $accessToken = null;
    private static int $tokenExpiry = 0;

    private static function serviceAccount(): ?array
    {
        if (self::$sa !== null) return self::$sa ?: null;
        $path = (string) Config::get('fcm.service_account', ROOT_PATH . '/storage/fcm-service-account.json');
        if (!is_file($path)) { self::$sa = []; return null; }
        $json = json_decode((string) file_get_contents($path), true);
        self::$sa = (is_array($json) && isset($json['client_email'], $json['private_key'], $json['token_uri'], $json['project_id'])) ? $json : [];
        return self::$sa ?: null;
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function accessToken(): ?string
    {
        if (self::$accessToken !== null && time() < self::$tokenExpiry - 60) return self::$accessToken;
        $sa = self::serviceAccount();
        if (!$sa) return null;

        $now    = time();
        $claims = [
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $sa['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $input = self::b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . self::b64url((string) json_encode($claims));
        $sig   = '';
        if (!openssl_sign($input, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return null;
        $jwt = $input . '.' . self::b64url($sig);

        $ch = curl_init($sa['token_uri']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $res, true);
        if ($code !== 200 || !isset($data['access_token'])) return null;

        self::$accessToken = (string) $data['access_token'];
        self::$tokenExpiry = $now + (int) ($data['expires_in'] ?? 3600);
        return self::$accessToken;
    }

    /**
     * Send one notification (title/body) plus a data map to each token, one
     * request per token. Prunes tokens FCM reports as gone. Returns #sent.
     */
    public static function sendToTokens(array $tokens, string $title, string $body, array $data = []): int
    {
        $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
        if (!$tokens) return 0;
        $sa     = self::serviceAccount();
        $access = self::accessToken();
        if (!$sa || !$access) return 0;

        $url  = 'https://fcm.googleapis.com/v1/projects/' . $sa['project_id'] . '/messages:send';
        $sent = 0;
        foreach ($tokens as $t) {
            $message = [
                'message' => [
                    'token'        => $t,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => array_map('strval', $data),
                    'android'      => ['priority' => 'high', 'notification' => ['sound' => 'default', 'default_sound' => true, 'channel_id' => 'call_in']],
                ],
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => (string) json_encode($message),
                CURLOPT_TIMEOUT        => 10,
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200) {
                $sent++;
            } elseif ($code === 404 || $code === 400) {
                $err    = json_decode((string) $res, true);
                $status = $err['error']['details'][0]['errorCode'] ?? ($err['error']['status'] ?? '');
                if (in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                    Database::getInstance()->delete('device_tokens', 'token = :t', [':t' => $t]);
                }
            }
        }
        return $sent;
    }
}
