<?php

declare(strict_types=1);

use App\Domain\Integrations\AnswerEvaluationPolicy;

/** Durable append-only reservations, independently of the evidence destination.
 * Failed/unknown attempts retain their complete conservative reservation.
 *
 * @return int total reserved microUSD after this admission */
/** @param array<string,mixed>|null $policy */
function reserveAnswerEvaluation(string $privateRoot, string $case, string $basis, string $referenceHash, ?array $policy = null): int
{
    $policy = AnswerEvaluationPolicy::validate($policy ?? AnswerEvaluationPolicy::current());
    $reservation = $policy['case_reservation_micro_usd'];
    $ledgerFile = $privateRoot.'/live-budget-ledger.jsonl';
    $anchorFile = $privateRoot.'/live-budget-anchor.json';
    $lockFile = $privateRoot.'/live-budget.lock';
    foreach ([$ledgerFile, $anchorFile, $lockFile] as $file) {
        if (is_link($file)) {
            throw new RuntimeException('Symlinked budget state refused.');
        }
    }
    $lock = fopen($lockFile, 'c+');
    if ($lock === false || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException('Budget lock unavailable.');
    }
    chmod($lockFile, 0600);
    $ledger = null;
    try {
        if (is_file($anchorFile) !== is_file($ledgerFile)) {
            throw new RuntimeException('Incomplete budget history refused.');
        }
        $first = ! is_file($ledgerFile);
        $ledger = fopen($ledgerFile, $first ? 'x+b' : 'a+b');
        if ($ledger === false) {
            throw new RuntimeException('Budget history unavailable.');
        }
        chmod($ledgerFile, 0600);
        if ($first) {
            $probe = json_encode(['case' => 'earlier-minimal-access-probe', 'reserved_micro_usd' => 1824, 'actual_input_tokens' => 36, 'actual_output_tokens' => 13, 'status' => 'completed'], JSON_THROW_ON_ERROR)."\n";
            if (fwrite($ledger, $probe) !== strlen($probe) || ! fflush($ledger) || ! fsync($ledger)) {
                throw new RuntimeException('Probe reservation not durable.');
            }
            $stat = fstat($ledger);
            $anchor = fopen($anchorFile, 'x');
            if ($anchor === false) {
                throw new RuntimeException('Budget anchor unavailable.');
            }
            chmod($anchorFile, 0600);
            try {
                $bytes = json_encode(['inode' => $stat['ino'], 'probe_sha256' => hash('sha256', $probe), 'length' => strlen($probe), 'prefix_sha256' => hash('sha256', $probe)], JSON_THROW_ON_ERROR);
                if (fwrite($anchor, $bytes) !== strlen($bytes) || ! fflush($anchor) || ! fsync($anchor)) {
                    throw new RuntimeException('Budget anchor not durable.');
                }
            } finally {
                fclose($anchor);
            }
        }
        $anchor = json_decode(file_get_contents($anchorFile), true, flags: JSON_THROW_ON_ERROR);
        $stat = fstat($ledger);
        if ($anchor['inode'] !== $stat['ino']) {
            throw new RuntimeException('Replaced budget ledger refused.');
        }
        rewind($ledger);
        $bytes = stream_get_contents($ledger);
        if ($bytes === '' || ! str_ends_with($bytes, "\n")) {
            throw new RuntimeException('Truncated budget ledger refused.');
        }
        if (strlen($bytes) < $anchor['length'] || ! hash_equals($anchor['prefix_sha256'], hash('sha256', substr($bytes, 0, $anchor['length'])))) {
            throw new RuntimeException('Budget history rewound or changed.');
        }
        $lines = explode("\n", rtrim($bytes, "\n"));
        if (! hash_equals($anchor['probe_sha256'], hash('sha256', $lines[0]."\n"))) {
            throw new RuntimeException('Budget prefix changed.');
        }
        $total = 0;
        foreach ($lines as $line) {
            $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (! is_int($entry['reserved_micro_usd'] ?? null) || $entry['reserved_micro_usd'] < 1) {
                throw new RuntimeException('Invalid budget history.');
            }
            $total += $entry['reserved_micro_usd'];
        }
        // New approved model:8,192 input plus512 output; legacy reservations stay intact.
        if ($total + $reservation > $policy['total_cap_micro_usd']) {
            throw new RuntimeException('Synthetic evaluation budget exhausted.');
        }
        $line = json_encode(['case' => $case, 'basis_sha256' => $basis, 'reference_sha256' => $referenceHash, 'reserved_micro_usd' => $reservation, 'model' => $policy['model'], 'policy_sha256' => AnswerEvaluationPolicy::digest(), 'max_input_tokens' => $policy['max_input_tokens'], 'max_output_tokens' => $policy['max_output_tokens'], 'status' => 'reserved_before_call'], JSON_THROW_ON_ERROR)."\n";
        if (fwrite($ledger, $line) !== strlen($line) || ! fflush($ledger) || ! fsync($ledger)) {
            throw new RuntimeException('Budget reservation not durable.');
        }
        $anchor['length'] = strlen($bytes.$line);
        $anchor['prefix_sha256'] = hash('sha256', $bytes.$line);
        $watermark = fopen($anchorFile, 'wb');
        if ($watermark === false) {
            throw new RuntimeException('Budget watermark unavailable.');
        }
        try {
            $record = json_encode($anchor, JSON_THROW_ON_ERROR);
            if (fwrite($watermark, $record) !== strlen($record) || ! fflush($watermark) || ! fsync($watermark)) {
                throw new RuntimeException('Budget watermark not durable.');
            }
        } finally {
            fclose($watermark);
        }

        return $total + $reservation;
    } finally {
        if (is_resource($ledger)) {
            fclose($ledger);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
