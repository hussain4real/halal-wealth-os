<?php

namespace Database\Factories;

use App\Domain\Operations\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Synthetic fixtures opt into operational allowances, never provider consent/access.
     * Real registrations have no policy and remain stopped until explicit owner setup. */
    public function configure(): static
    {
        return $this->afterCreating(function (User $owner): void {
            if (Schema::hasTable('operation_controls')) {
                $rows = [];
                foreach (Channel::cases() as $channel) {
                    $rows[] = ['owner_id' => $owner->id, 'channel' => $channel->value, 'paused' => false,
                        'requests_daily' => $channel->maximumRequests(), 'units_daily' => $channel->maximumUnits(), 'retention_days' => 7];
                }
                DB::table('operation_controls')->insert($rows);
            }
        });
    }

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
