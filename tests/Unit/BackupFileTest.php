<?php

use Symfony\Component\Process\Process;

test('private backup file tool round trips and refuses wrong keys unsafe permissions and overwrites', function () {
    $dir = sys_get_temp_dir().'/hwo-backup-tool-'.bin2hex(random_bytes(10));
    mkdir($dir, 0700);
    try {
        foreach (['input' => random_bytes(90000), 'key' => random_bytes(32), 'wrong' => random_bytes(32)] as $name => $bytes) {
            file_put_contents($dir.'/'.$name, $bytes);
            chmod($dir.'/'.$name, 0600);
        }
        $run = function (string $mode, string $input, string $output, string $key) use ($dir): Process {
            $process = new Process([PHP_BINARY, 'scripts/backup-file.php', $mode, $dir.'/'.$input, $dir.'/'.$output, $dir.'/'.$key], dirname(__DIR__, 2));
            $process->run();

            return $process;
        };
        expect($run('seal', 'input', 'sealed', 'key')->getExitCode())->toBe(0)
            ->and($run('open', 'sealed', 'restored', 'key')->getExitCode())->toBe(0)
            ->and(hash_file('sha256', $dir.'/input'))->toBe(hash_file('sha256', $dir.'/restored'))
            ->and(fileperms($dir.'/sealed') & 0777)->toBe(0600)
            ->and($run('open', 'sealed', 'rejected', 'wrong')->getExitCode())->toBe(1)
            ->and(file_exists($dir.'/rejected'))->toBeFalse()
            ->and($run('seal', 'input', 'restored', 'key')->getExitCode())->toBe(1)
            ->and(hash_file('sha256', $dir.'/restored'))->toBe(hash_file('sha256', $dir.'/input'));
        chmod($dir.'/key', 0644);
        expect($run('seal', 'input', 'unsafe', 'key')->getExitCode())->toBe(1)->and(file_exists($dir.'/unsafe'))->toBeFalse();
    } finally {
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
});
