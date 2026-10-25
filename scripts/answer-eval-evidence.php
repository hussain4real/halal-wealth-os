<?php

declare(strict_types=1);

/** Only complete, durable JSON checkpoints may be used as retained evidence. */
function writeAnswerEvaluationEvidence(string $path, array $record): void
{
    if (! is_dir(dirname($path)) || is_link($path)) {
        throw new RuntimeException('Private evaluation evidence destination refused.');
    }
    $temporary = $path.'.pending-'.bin2hex(random_bytes(8));
    $file = fopen($temporary, 'x+b');
    if ($file === false) {
        throw new RuntimeException('Private evidence write unavailable.');
    }
    chmod($temporary, 0600);
    try {
        $bytes = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file) || ! fsync($file)) {
            throw new RuntimeException('Private evidence write incomplete.');
        }
    } finally {
        fclose($file);
    }
    if (! rename($temporary, $path)) {
        throw new RuntimeException('Private evidence checkpoint not committed.');
    }
}
