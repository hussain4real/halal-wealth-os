<?php

use App\Support\Release\EncryptedBackup;

test('encrypted backup roundtrips empty and multi-frame binary archives', function (string $data) {
    $source = tmpfile();
    $archive = tmpfile();
    $restored = tmpfile();
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    try {
        fwrite($source, $data);
        rewind($source);
        EncryptedBackup::seal($source, $archive, $key);
        rewind($archive);
        $ciphertext = stream_get_contents($archive);
        if ($data !== '') {
            expect($ciphertext)->not->toContain($data);
        }
        rewind($archive);
        EncryptedBackup::open($archive, $restored, $key);
        rewind($restored);
        expect(stream_get_contents($restored))->toBe($data);
    } finally {
        foreach ([$source, $archive, $restored] as $file) {
            fclose($file);
        }
        sodium_memzero($key);
    }
})->with(['empty' => [''], 'binary' => [str_repeat("synthetic\x00\xff\x10", 20000)]]);

test('damaged encrypted backup rejects the complete archive before writing any plaintext', function (string $damage) {
    $source = tmpfile();
    $archive = tmpfile();
    $damaged = tmpfile();
    $restored = tmpfile();
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    try {
        fwrite($source, str_repeat('Synthetic operational database content.', 5000));
        rewind($source);
        EncryptedBackup::seal($source, $archive, $key);
        rewind($archive);
        $bytes = stream_get_contents($archive);
        $bytes = match ($damage) {
            'header' => substr_replace($bytes, "\x02", 14, 1),
            'ciphertext' => substr_replace($bytes, $bytes[100] ^ "\x01", 100, 1),
            'truncated' => substr($bytes, 0, -1),
            'missing final' => substr($bytes, 0, -21),
            'trailing' => $bytes.'unexpected suffix',
            'oversized frame' => substr_replace($bytes, pack('N', 2000000), 39, 4),
            'wrong key' => $bytes,
        };
        fwrite($damaged, $bytes);
        rewind($damaged);
        expect(fn () => EncryptedBackup::open($damaged, $restored, $damage === 'wrong key' ? random_bytes(32) : $key))->toThrow(RuntimeException::class);
        expect(fstat($restored)['size'])->toBe(0);
    } finally {
        foreach ([$source, $archive, $damaged, $restored] as $file) {
            fclose($file);
        }
        sodium_memzero($key);
    }
})->with(['header', 'ciphertext', 'truncated', 'missing final', 'trailing', 'oversized frame', 'wrong key']);
