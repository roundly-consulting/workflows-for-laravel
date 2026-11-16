<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

use RoundlyConsulting\Workflows\Exceptions\InvalidFailureDetailsException;

/**
 * The structured details a step failure may carry beside its code and message (an agent run's
 * id and stop reason, an upstream request id). Normalised once, where the step author hands them
 * over, so the stored row, the read API and the events all see the same value:
 *
 * - **Refused** (InvalidFailureDetailsException): anything that is not plain JSON — an object, a
 *   model, a closure, a resource, INF/NAN, nesting deeper than 32 — and a list at the top (details
 *   are a JSON object). That is a code mistake, so it is loud.
 * - **Redacted**: the value under every key that names a secret, at any depth, becomes
 *   `"[redacted]"` (see {@see self::secret()}).
 * - **Repaired**: invalid UTF-8 becomes U+FFFD, so encoding the row can never fail.
 * - **Truncated**: over `workflows.failure_details.max_bytes` (JSON-encoded, after redaction) the
 *   details become `{"truncated": true, "bytes": n}`: the size depends on runtime data, so it never
 *   throws.
 *
 * Empty details are no details (`null`).
 *
 * @internal
 */
final class FailureDetails
{
    public const string REDACTED = '[redacted]';

    private const int MAX_DEPTH = 32;

    private const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * A key holding one of these words, anywhere, names a secret.
     */
    private const array SECRET_WORDS = [
        'password', 'passwd', 'pwd', 'passphrase', 'secret', 'secrets', 'credential', 'credentials',
        'cookie', 'cookies', 'authorization', 'apikey', 'apikeys', 'privatekey', 'bearer', 'otp', 'cvv',
        'cvc', 'dsn',
    ];

    /**
     * A key ending in `key` after one of these words names a secret (`api_key`, `privateKey`).
     */
    private const array KEY_QUALIFIERS = [
        'api', 'private', 'secret', 'access', 'signing', 'encryption', 'client', 'master', 'license', 'session', 'auth',
    ];

    /**
     * @param  array<array-key, mixed>|null  $details
     * @return array<array-key, mixed>|null
     *
     * @throws InvalidFailureDetailsException
     */
    public static function normalise(?array $details): ?array
    {
        if ($details === null || $details === []) {
            return null;
        }

        if (array_is_list($details)) {
            throw InvalidFailureDetailsException::notAnObject();
        }

        self::assertPlain($details, '', 0);

        $encoded = (string) json_encode(self::redact($details), self::FLAGS);
        $bytes = strlen($encoded);

        if ($bytes > WorkflowsConfig::failureDetailsMaxBytes()) {
            return ['truncated' => true, 'bytes' => $bytes];
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether a details key names a secret. Words are split on anything but letters and digits and
     * on camelCase (`clientSecret`, `x-api-key`, `ACCESS_TOKEN`), then: any of the secret words
     * (`password`, `secret`, `credentials`, `cookie`, `authorization`, …); a last word `token`
     * (`access_token`, not `input_tokens` or `token_count`); a last word `key` after a qualifier
     * (`api_key`, `private_key`, `secretKey`); a last word `auth`; and `session_id`.
     */
    public static function secret(string $key): bool
    {
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key));
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', $snake) ?: [], static fn (string $word): bool => $word !== ''));

        if ($words === []) {
            return false;
        }

        if (array_intersect($words, self::SECRET_WORDS) !== []) {
            return true;
        }

        $last = $words[count($words) - 1];
        $previous = $words[count($words) - 2] ?? null;

        return match ($last) {
            'token', 'auth' => true,
            'key' => in_array($previous, self::KEY_QUALIFIERS, true),
            'id' => $previous === 'session',
            default => false,
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @throws InvalidFailureDetailsException
     */
    private static function assertPlain(array $value, string $path, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            throw InvalidFailureDetailsException::notPlain($path === '' ? '(root)' : $path);
        }

        foreach ($value as $key => $item) {
            $at = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($item)) {
                self::assertPlain($item, $at, $depth + 1);

                continue;
            }

            if ($item === null || is_string($item) || is_int($item) || is_bool($item) || (is_float($item) && is_finite($item))) {
                continue;
            }

            throw InvalidFailureDetailsException::notPlain($at);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && self::secret($key)) {
                $value[$key] = self::REDACTED;
            } elseif (is_array($item)) {
                $value[$key] = self::redact($item);
            }
        }

        return $value;
    }
}
