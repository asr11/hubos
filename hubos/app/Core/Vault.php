<?php

declare(strict_types=1);

namespace HubOS\Core;

use RuntimeException;

/**
 * Vault secret encryption: AES-256-GCM via openssl (no sodium dependency).
 * Key: env HUBOS_VAULT_KEY = 32 bytes as base64 or 64 hex chars.
 * Format: 'v1:' . base64(iv[12] . tag[16] . ciphertext).
 */
final class Vault
{
    private const CIPHER = 'aes-256-gcm';

    private function __construct(private string $key)
    {
    }

    /** Returns null when no key is configured (caller must NOT store plaintext). */
    public static function fromEnv(): ?self
    {
        $raw = getenv('HUBOS_VAULT_KEY');
        if ($raw === false || $raw === '') {
            return null;
        }
        return self::fromKeyString($raw);
    }

    public static function fromKeyString(string $raw): self
    {
        $raw = trim($raw);
        $key = null;
        if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
            $key = hex2bin($raw);
        } else {
            $decoded = base64_decode($raw, true);
            if ($decoded !== false && strlen($decoded) === 32) {
                $key = $decoded;
            }
        }
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('HUBOS_VAULT_KEY must be 32 bytes (base64 or 64 hex chars)');
        }
        if (!in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('openssl aes-256-gcm is not available');
        }
        return new self($key);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false || strlen($tag) !== 16) {
            throw new RuntimeException('Vault encryption failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public function decrypt(string $blob): string
    {
        if (!str_starts_with($blob, 'v1:')) {
            throw new RuntimeException('Unknown vault blob format');
        }
        $bin = base64_decode(substr($blob, 3), true);
        if ($bin === false || strlen($bin) < 28) {
            throw new RuntimeException('Corrupt vault blob');
        }
        $pt = openssl_decrypt(substr($bin, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        if ($pt === false) {
            throw new RuntimeException('Vault decryption failed (wrong key or tampered data)');
        }
        return $pt;
    }
}
