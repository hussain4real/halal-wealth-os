<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Support\Release\EncryptedBackup;

// This tool transforms private files only. It never connects to a database.
$output = null;
$key = null;
$streams = [];
try {
    if ($argc !== 5 || ! in_array($argv[1], ['seal', 'open'], true)) {
        throw new RuntimeException('Usage: php scripts/backup-file.php seal|open INPUT OUTPUT EXISTING_RAW_32_BYTE_KEY_FILE');
    }
    [$script, $mode, $input, $destination, $keyFile] = $argv;
    foreach ([$input, $keyFile] as $file) {
        if (! is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0 || ! is_readable($file)) {
            throw new RuntimeException('Input and existing key must be private regular files.');
        }
    }
    $parent = realpath(dirname($destination));
    if ($parent === false || is_link(dirname($destination)) || (fileperms($parent) & 0077) !== 0) {
        throw new RuntimeException('Output requires a private directory.');
    }
    $key = file_get_contents($keyFile);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
        throw new RuntimeException('Existing backup key must contain exactly 32 raw bytes.');
    }
    $in = fopen($input, 'rb');
    if ($in === false) {
        throw new RuntimeException('Private input could not be opened.');
    }
    $streams[] = $in;
    $out = fopen($destination, 'x+b');
    if ($out === false) {
        throw new RuntimeException('Refusing an existing or unavailable destination.');
    }
    $output = $destination;
    $streams[] = $out;
    if (! chmod($destination, 0600)) {
        throw new RuntimeException('Private output permissions could not be applied.');
    }
    if ($mode === 'seal') {
        EncryptedBackup::seal($in, $out, $key);
    } else {
        EncryptedBackup::open($in, $out, $key);
    }
    if (! fflush($out)) {
        throw new RuntimeException('Private output could not be flushed.');
    }
    $output = null;
    echo "PASS: private file transformation completed.\n";
} catch (Throwable) {
    fwrite(STDERR, "FAIL: private backup transformation refused or failed; no successful output is claimed.\n");
    $failed = true;
} finally {
    foreach ($streams as $stream) {
        fclose($stream);
    }
    if ($output !== null) {
        unlink($output);
    }
    if (is_string($key)) {
        sodium_memzero($key);
    }
}
exit(isset($failed) ? 1 : 0);
