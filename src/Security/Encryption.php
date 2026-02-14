<?php

declare(strict_types=1);

namespace ProductResearch\Security;

/**
 * AES-256-CBC encryption helper for API keys at rest.
 *
 * Uses WordPress LOGGED_IN_SALT as the encryption key.
 *
 * @package ProductResearch\Security
 * @since   1.0.0
 */
final class Encryption
{
    private const CIPHER = 'aes-256-cbc';

    /**
     * Encrypt a plaintext value.
     *
     * Returns an empty string if the input is empty or encryption fails.
     *
     * @since 1.0.0
     *
     * @param  string $value Plain-text value to encrypt.
     * @return string Base64-encoded ciphertext (IV prepended), or empty on failure.
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
     *
     * Returns an empty string if the input is empty or decryption fails.
     *
     * @since 1.0.0
     *
     * @param  string $encrypted Base64-encoded ciphertext.
     * @return string Decrypted plain-text, or empty on failure.
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
     *
     * Falls back to a default salt if LOGGED_IN_SALT is not defined.
     *
     * @since 1.0.0
     *
     * @return string 32-byte binary SHA-256 hash.
     */
    private function getKey(): string
    {
        $salt = defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : 'pr-default-salt';

        return hash('sha256', $salt, true);
    }
}
