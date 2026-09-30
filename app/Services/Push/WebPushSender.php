<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Web Push sender — RFC 8030 transport with RFC 8292 (VAPID) authentication
 * and RFC 8291 (aes128gcm) payload encryption, implemented with core PHP
 * openssl primitives only (no composer packages).
 *
 * Graceful fallback is a contract: when VAPID keys are not configured the
 * sender reports itself disabled and callers deliver in-app database
 * notifications only. Transport failures never throw into the caller —
 * delivery is best-effort by design (the database notification is the
 * reliable record).
 */
class WebPushSender
{
    /** @var null|callable(string $url, array $headers, string $body): array{0: int, 1: string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function isEnabled(): bool
    {
        $private = (string) config('push.vapid_private_key');

        return $private !== '' && $this->loadPrivateKey($private) !== null;
    }

    public function vapidPublicKey(): ?string
    {
        $public = (string) config('push.vapid_public_key');

        return $public !== '' ? $public : null;
    }

    /**
     * Send a data-carried push message to every subscription of a user.
     * Dead endpoints (404/410) are removed. Returns per-endpoint statuses.
     *
     * @return array<int, array{endpoint: string, status: int}>
     */
    public function sendToUser(User $user, string $title, string $body, ?string $url = null, array $data = []): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        $results = [];
        foreach ($user->pushSubscriptions as $subscription) {
            $status = $this->sendToSubscription($subscription, (string) $payload);
            $results[] = ['endpoint' => $subscription->endpoint, 'status' => $status];
            if ($status === 404 || $status === 410) {
                $subscription->delete();
            }
        }

        return $results;
    }

    /**
     * Encrypt and deliver one payload to one subscription. Returns the HTTP
     * status (0 = transport/config failure).
     */
    public function sendToSubscription(PushSubscription $subscription, string $payload): int
    {
        $uaPublic = self::b64urlDecode((string) $subscription->p256dh());
        $uaAuth = self::b64urlDecode((string) $subscription->authKey());
        if (strlen($uaPublic) !== 65 || strlen($uaAuth) !== 16) {
            return 0;
        }

        try {
            $body = $this->encrypt($payload, $uaPublic, $uaAuth);
        } catch (\Throwable) {
            return 0;
        }

        $jwt = $this->vapidJwt($subscription->endpoint);
        $headers = [
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: ' . (int) config('push.ttl', 86400),
            'Urgency: normal',
            'Authorization: vapid t=' . $jwt . ', k=' . (string) config('push.vapid_public_key'),
        ];

        return $this->post($subscription->endpoint, $headers, $body);
    }

    /**
     * RFC 8291 §3.2 — aes128gcm content coding.
     */
    private function encrypt(string $plaintext, string $uaPublic, string $uaAuth): string
    {
        $asPrivate = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($asPrivate === false) {
            throw new \RuntimeException('Unable to create ECDH key');
        }

        $details = openssl_pkey_get_details($asPrivate);
        $asPublic = "\x04" . $details['ec']['x'] . $details['ec']['y'];

        $uaKey = openssl_pkey_get_public(self::spkiPem($uaPublic));
        if ($uaKey === false) {
            throw new \RuntimeException('Invalid subscriber key');
        }

        $shared = openssl_pkey_derive($uaKey, $asPrivate, 32);
        if ($shared === false) {
            throw new \RuntimeException('ECDH derive failed');
        }

        // IKM = HKDF-Expand(HKDF-Extract(auth, shared), "WebPush: info\0" || ua_pub || as_pub, 32)
        $prkKey = self::hkdfExtract($uaAuth, $shared);
        $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
        $ikm = self::hkdfExpand($prkKey, $keyInfo, 32);

        $salt = random_bytes(16);
        $prk = self::hkdfExtract($salt, $ikm);
        $cek = self::hkdfExpand($prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = self::hkdfExpand($prk, "Content-Encoding: nonce\x00", 12);

        $lastRecord = $plaintext . "\x02"; // padding delimiter, no padding
        $tag = '';
        $cipher = openssl_encrypt($lastRecord, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('AES-GCM failed');
        }

        // salt(16) || rs(4) || idlen(1) || as_public(65) || ciphertext||tag
        return $salt . pack('N', 4096) . "\x41" . $asPublic . $cipher . $tag;
    }

    /**
     * RFC 8292 — VAPID JWT (ES256) for the endpoint's origin.
     */
    private function vapidJwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $header = self::b64urlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64urlEncode((string) json_encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => (string) config('push.vapid_subject'),
        ]));
        $signingInput = $header . '.' . $payload;

        $key = $this->loadPrivateKey((string) config('push.vapid_private_key'));
        if ($key === null) {
            throw new \RuntimeException('VAPID private key not configured');
        }

        $der = '';
        if (! openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID sign failed');
        }

        return $signingInput . '.' . self::b64urlEncode(self::derToRawEcdsa($der));
    }

    private function post(string $url, array $headers, string $body): int
    {
        if ($this->transport !== null) {
            [$status] = ($this->transport)($url, $headers, $body);

            return (int) $status;
        }

        $headerMap = ['User-Agent' => 'teacher-system-webpush/1.0'];
        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(': ', $header, 2), 2, '');
            $headerMap[$name] = $value;
        }

        try {
            $response = Http::withHeaders($headerMap)
                ->withBody($body, 'application/octet-stream')
                ->post($url);

            return $response->status();
        } catch (\Throwable) {
            // Transport failure: the database notification remains the record.
            return 0;
        }
    }

    /**
     * @return \OpenSSLAsymmetricKey|resource|null
     */
    private function loadPrivateKey(string $pem)
    {
        if (! str_contains($pem, 'BEGIN')) {
            // Allow storing the raw base64 DER of a PKCS#8 EC private key.
            $der = base64_decode($pem, true);
            if ($der === false) {
                return null;
            }
            $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----";
        }

        return openssl_pkey_get_private($pem);
    }

    private static function spkiPem(string $raw65): string
    {
        // SubjectPublicKeyInfo header for an uncompressed P-256 point.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";
    }

    private static function hkdfExtract(string $salt, string $ikm): string
    {
        return hash_hmac('sha256', $ikm, $salt, true);
    }

    private static function hkdfExpand(string $prk, string $info, int $len): string
    {
        $out = '';
        $t = '';
        $i = 1;
        while (strlen($out) < $len) {
            $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $out .= $t;
            $i++;
        }

        return substr($out, 0, $len);
    }

    /** Convert DER ECDSA signature to raw r||s (32 + 32 bytes). */
    private static function derToRawEcdsa(string $der): string
    {
        $offset = 2; // SEQUENCE header
        $raw = '';
        foreach (['r', 's'] as $_) {
            if (ord($der[$offset]) !== 0x02) {
                throw new \RuntimeException('Bad DER signature');
            }
            $len = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $len);
            $offset += 2 + $len;
            $value = ltrim($value, "\x00");
            $raw .= str_pad($value, 32, "\x00", STR_PAD_LEFT);
        }

        return $raw;
    }

    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
