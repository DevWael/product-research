<?php

declare(strict_types=1);

namespace ProductResearch\Security;

/**
 * AES-256-CBC encryption helper for API keys at rest.
 *
 * Uses WordPress LOGGED_IN_SALT as the encryption key.
 */
final class Encryption
{
    private const CIPHER = 'aes-256-cbc';

    /**
     * Encrypt a plaintext value.
     */
    public function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key    = $this->getKey();
        $iv     = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));
        $cipher = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            return '';
        }

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt an encrypted value.
     */
    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $key  = $this->getKey();
        $data = base64_decode($encrypted, true);

        if ($data === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv       = substr($data, 0, $ivLength);
        $cipher   = substr($data, $ivLength);

        $decrypted = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Derive encryption key from WordPress salt.
     */
    private function getKey(): string
    {
        $salt = defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : 'pr-default-salt';

        return hash('sha256', $salt, true);
    }
}
