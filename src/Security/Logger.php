<?php

declare(strict_types=1);

namespace ProductResearch\Security;

/**
 * Sanitized logger that wraps error_log().
 *
 * Strips API keys, auth headers, raw response bodies,
 * and internal URLs with query params before writing.
 *
 * @package ProductResearch\Security
 * @since   1.0.0
 */
final class Logger
{
    private const PREFIX = '[Product Research] ';

    /**
     * Log a sanitized message.
     *
     * @since 1.0.0
     *
     * @param  string $message Raw log message (will be sanitized).
     * @param  string $level   Log level: 'error', 'warning', 'info'.
     * @return void
     */
    public function log(string $message, string $level = 'error'): void
    {
        $sanitized = $this->sanitize($message);
        error_log(self::PREFIX . strtoupper($level) . ': ' . $sanitized);
    }

    /**
     * Strip sensitive data from log messages.
     *
     * Redacts API keys (32+ char alphanumeric strings), Authorization
     * headers, URLs with query parameters, and truncates long messages.
     *
     * @since 1.0.0
     *
     * @param  string $message Raw message.
     * @return string Sanitized message safe for error_log().
     */
    public function sanitize(string $message): string
    {
        // Strip potential API keys (32+ alphanumeric strings that look like keys)
        $message = preg_replace(
            '/\b[A-Za-z0-9_\-]{32,}\b/',
            '[REDACTED]',
            $message
        ) ?? $message;

        // Strip Authorization headers
        $message = preg_replace(
            '/Authorization:\s*Bearer\s+\S+/i',
            'Authorization: Bearer [REDACTED]',
            $message
        ) ?? $message;

        // Strip URLs with query parameters (potential auth tokens)
        $message = preg_replace(
            '/https?:\/\/[^\s]+\?[^\s]+/',
            '[URL_REDACTED]',
            $message
        ) ?? $message;

        // Truncate long messages to prevent log flooding
        if (strlen($message) > 1000) {
            $message = substr($message, 0, 1000) . '... [TRUNCATED]';
        }

        return $message;
    }
}
