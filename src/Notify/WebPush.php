<?php
declare(strict_types=1);

namespace One\Notify;

use RuntimeException;

/**
 * Minimal Web Push sender — no Composer, only ext-openssl + ext-curl.
 *  - Payload encryption: RFC 8291 (aes128gcm, RFC 8188), one record.
 *  - Authentication: VAPID (RFC 8292), ES256 JWT.
 *
 * config/push.php (gitignored, PROTECTED_CONFIGS), created by `php bin/push-keys.php`:
 *   ['public_key' => '<base64url, 65 bytes>', 'private_key_pem' => '-----BEGIN EC PRIVATE KEY-----…', 'subject' => 'mailto:…']
 */
final class WebPush
{
    /** DER prefix of an X.509 SubjectPublicKeyInfo for a P-256 uncompressed point. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c !== null && !empty($c['public_key']) && !empty($c['private_key_pem']);
    }

    public static function publicKey(): string
    {
        return (string) (self::config()['public_key'] ?? '');
    }

    /** New VAPID key pair. @return array{public_key:string, private_key_pem:string} */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('OpenSSL nu poate genera chei P-256.');
        }
        return ['public_key' => self::b64u(self::rawPublic($key)), 'private_key_pem' => $pem];
    }

    /**
     * Sends one payload to many subscriptions in parallel.
     * @param array<int|string, array{endpoint:string, p256dh:string, auth:string}> $subs
     * @param array{ttl?:int, urgency?:string, topic?:string} $options
     * @return array<int|string, int> key => HTTP status (0 = network error). 404/410 = subscription is gone.
     */
    public static function sendMany(array $subs, string $payload, array $options = []): array
    {
        if (!$subs) {
            return [];
        }
        $config = self::config() ?? throw new RuntimeException('config/push.php lipsește.');
        $multi = curl_multi_init();
        $handles = [];
        $jwts = [];
        foreach ($subs as $key => $sub) {
            try {
                $body = self::encrypt($payload, $sub['p256dh'], $sub['auth']);
            } catch (\Throwable $e) {
                error_log('[ONE] push encrypt failed: ' . $e->getMessage());
                continue;
            }
            $audience = self::audience($sub['endpoint']);
            $jwts[$audience] ??= self::vapidJwt($audience, $config);
            $headers = [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . (int) ($options['ttl'] ?? 86400),
                'Urgency: ' . ($options['urgency'] ?? 'normal'),
                'Authorization: vapid t=' . $jwts[$audience] . ', k=' . $config['public_key'],
            ];
            if (!empty($options['topic'])) {
                // Replaces an undelivered message with the same topic on the push service.
                $headers[] = 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', $options['topic']) ?? '', 0, 32);
            }
            $ch = curl_init($sub['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $out[$key] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($out[$key] >= 400) {
                error_log(sprintf('[ONE] push HTTP %d: %s', $out[$key], substr((string) curl_multi_getcontent($ch), 0, 200)));
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $out;
    }

    /** RFC 8291 / RFC 8188 aes128gcm body (header + one record). Public for the self-test. */
    public static function encrypt(string $payload, string $p256dhB64u, string $authB64u, ?string $salt = null): string
    {
        $uaPublic = self::b64uDecode($p256dhB64u);
        $authSecret = self::b64uDecode($authB64u);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('Abonament push invalid (chei).');
        }

        $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::rawPublic($local);
        $peer = openssl_pkey_get_public(self::spkiPem($uaPublic));
        $ecdh = $peer ? openssl_pkey_derive($peer, $local, 32) : false;
        if ($ecdh === false) {
            throw new RuntimeException('ECDH eșuat.');
        }

        $salt ??= random_bytes(16);
        $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPublic . $asPublic . "\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('AES-GCM eșuat.');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    private static function vapidJwt(string $audience, array $config): string
    {
        $header = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => (string) ($config['subject'] ?? 'mailto:contact@radoiromeo.ro'),
        ], JSON_UNESCAPED_SLASHES));
        $data = "$header.$claims";
        if (!openssl_sign($data, $der, $config['private_key_pem'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Semnătura VAPID a eșuat.');
        }
        return $data . '.' . self::b64u(self::derToRaw($der));
    }

    /** ECDSA DER (SEQUENCE{INTEGER r, INTEGER s}) → raw r||s, 32 bytes each. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }
        return $out;
    }

    /** @param \OpenSSLAsymmetricKey $key */
    private static function rawPublic($key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function spkiPem(string $raw): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function audience(string $endpoint): string
    {
        $p = parse_url($endpoint);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    private static function config(): ?array
    {
        static $config = false;
        if ($config !== false) {
            return $config;
        }
        $path = ONE_ROOT . '/config/push.php';
        $data = is_file($path) ? require $path : null;
        return $config = is_array($data) ? $data : null;
    }
}
