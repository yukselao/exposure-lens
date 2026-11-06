<?php
declare(strict_types=1);

/**
 * Secrets at rest: libsodium secretbox, stored as "v1:" . base64(nonce . ciphertext).
 * The Python connectors (connectors/common/elcommon/crypto.py) read the same format
 * with the same key file. The web UI only ever encrypts; it never shows a stored secret.
 */
final class Crypto
{
    private const PREFIX = 'v1:';

    private static function key(): string
    {
        $hex = trim((string) @file_get_contents(getenv('APP_SECRET_KEY_FILE') ?: '/run/secrets/app_secret_key'));
        if (strlen($hex) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2 || !ctype_xdigit($hex)) {
            throw new RuntimeException('The secret key file is missing or is not 64 hex characters.');
        }
        return sodium_hex2bin($hex);
    }

    public static function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::key()));
    }
}
