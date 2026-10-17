<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Domain\Identity\SecurityAudit;
use App\Domain\Identity\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnboardOwner extends Command
{
    protected $signature = 'owner:onboard';

    protected $description = 'Interactively onboard the initial private owner; never prints credentials';

    public function handle(): int
    {
        if (! $this->input->isInteractive() || User::query()->exists()) {
            $this->error('Interactive initial onboarding requires an empty user store.');

            return self::FAILURE;
        }

        $name = $this->ask('Owner name');
        $email = Str::lower((string) $this->ask('Owner email'));
        $password = $this->secret('Owner password');
        $confirmation = $this->secret('Confirm owner password');
        if (! $this->confirm('Create the initial private owner with the explicit admin capability?')) {
            return self::FAILURE;
        }

        return DB::transaction(function () use ($name, $email, $password, $confirmation): int {
            // PostgreSQL transaction lock serializes simultaneous first-run commands.
            DB::select('SELECT pg_advisory_xact_lock(1337, 3)');
            if (User::query()->exists()) {
                $this->error('An owner already exists.');

                return self::FAILURE;
            }

            $owner = app(CreateNewUser::class)->create(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation]);
            $owner->forceFill(['is_admin' => true])->save();
            event(new Registered($owner));
            app(SecurityAudit::class)->record(SecurityEvent::OwnerOnboarded, null, ['subject_id' => $owner->id]);
            $this->info('Owner created. Verify email and confirm two-factor authentication before personal production use.');

            return self::SUCCESS;
        });
    }
}
