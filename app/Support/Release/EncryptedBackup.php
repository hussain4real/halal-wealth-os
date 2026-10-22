<?php

declare(strict_types=1);

namespace App\Support\Release;

use RuntimeException;
use SensitiveParameter;

final class EncryptedBackup
{
    private const string MAGIC = "HALALOS-BACKUP\x01";

    private const int CHUNK = 65536;

    /** @param resource $plain
     * @param resource $encrypted */
    public static function seal($plain, $encrypted, #[SensitiveParameter] string $key): void
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        try {
            self::write($encrypted, self::MAGIC.$header);
            while (! feof($plain)) {
                $chunk = fread($plain, self::CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException('Backup input could not be read.');
                }
                if ($chunk !== '') {
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, self::MAGIC);
                    self::write($encrypted, pack('N', strlen($cipher)).$cipher);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', self::MAGIC, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            self::write($encrypted, pack('N', strlen($final)).$final);
        } finally {
            sodium_memzero($state);
            sodium_memzero($key);
        }
    }

    /** Authenticate the complete held archive before writing any plaintext.
     * @param resource $encrypted
     * @param resource $plain */
    public static function open($encrypted, $plain, #[SensitiveParameter] string $key): void
    {
        $start = ftell($encrypted);
        if ($start === false || ! stream_get_meta_data($encrypted)['seekable'] || ! flock($encrypted, LOCK_SH)) {
            throw new RuntimeException('Backup must be a seekable, privately held archive.');
        }
        try {
            $fingerprint = self::readArchive($encrypted, null, $key);
            if (fseek($encrypted, $start) !== 0) {
                throw new RuntimeException('Backup could not be rewound.');
            }
            $restored = self::readArchive($encrypted, $plain, $key);
            if (! hash_equals($fingerprint, $restored)) {
                throw new RuntimeException('Backup changed during authenticated reading.');
            }
        } finally {
            flock($encrypted, LOCK_UN);
            sodium_memzero($key);
        }
    }

    /** @param resource $encrypted
     * @param resource|null $plain */
    private static function readArchive($encrypted, $plain, #[SensitiveParameter] string $key): string
    {
        $prefix = self::read($encrypted, strlen(self::MAGIC));
        if ($prefix !== self::MAGIC) {
            throw new RuntimeException('Unsupported or damaged backup format.');
        }
        $header = self::read($encrypted, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $hash = hash_init('sha256');
        hash_update($hash, $prefix.$header);
        try {
            while (true) {
                $lengthBytes = self::read($encrypted, 4);
                $decoded = unpack('Nlength', $lengthBytes);
                if ($decoded === false || ! isset($decoded['length']) || ! is_int($decoded['length'])) {
                    throw new RuntimeException('Invalid backup frame length.');
                }
                $length = $decoded['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Invalid backup frame length.');
                }
                $cipher = self::read($encrypted, $length);
                hash_update($hash, $lengthBytes.$cipher);
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, self::MAGIC);
                if ($opened === false) {
                    throw new RuntimeException('Backup authentication failed.');
                }
                [$chunk, $tag] = $opened;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($chunk !== '' || fread($encrypted, 1) !== '') {
                        throw new RuntimeException('Backup must end at its authenticated final frame.');
                    }

                    return hash_final($hash);
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $chunk === '') {
                    throw new RuntimeException('Unsupported backup frame.');
                }
                if ($plain !== null) {
                    self::write($plain, $chunk);
                }
            }
        } finally {
            sodium_memzero($state);
            sodium_memzero($key);
        }
    }

    /** @param resource $stream */
    private static function read($stream, int $length): string
    {
        $bytes = '';
        while (true) {
            $remaining = $length - strlen($bytes);
            if ($remaining <= 0) {
                break;
            }
            $part = fread($stream, $remaining);
            if ($part === false || $part === '') {
                throw new RuntimeException('Backup is truncated or unreadable.');
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    /** @param resource $stream */
    private static function write($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Backup output could not be written.');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
