<?php

namespace App\Console\Commands;

use App\Domain\Decisions\DecisionService;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

final class ImportDecisionPlan extends Command
{
    protected $signature = 'decisions:import-plan {owner} {path} {--confirm-private} {--request-key=}';

    protected $description = 'Opt in to an external private opportunity plan without posting cash or investments';

    public function handle(DecisionService $service): int
    {
        $argument = $this->argument('path');
        $path = is_string($argument) ? realpath($argument) : false;
        if (! $this->option('confirm-private') || $path === false || ! is_file($path) || ! is_readable($path) || filesize($path) > 64000 || str_starts_with($path, realpath(base_path()).DIRECTORY_SEPARATOR)) {
            $this->error('Confirm private import and choose a readable JSON file outside the checkout, up to 64 KB.');

            return self::FAILURE;
        }
        $owner = User::query()->find($this->argument('owner'));
        if (! $owner instanceof User) {
            $this->error('An existing owner is required. No user was created.');

            return self::FAILURE;
        }
        try {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('Private input unavailable');
            }
            $input = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($input) || array_is_list($input) || ($input['prior_id'] ?? '') !== '') {
                $this->error('The private input must be a new plan object.');

                return self::FAILURE;
            }
            $key = $this->option('request-key');
            if ($key === null || $key === '') {
                $key = 'private-plan:'.hash('sha256', $bytes);
            }
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Invalid private request identity');
            }
            $row = $service->plan($owner, $key, $input);
            $this->info('Private plan receipt: '.$row->reference.'. No reservation or financial event was created.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Private plan was not imported. Review its fields and request identity. Details and contents were not printed.');

            return self::FAILURE;
        }
    }
}
