<?php

namespace Tests\Support;

use App\Domain\Intelligence\CopilotBoundary;
use App\Domain\Reporting\Copilot\ToolQuery;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CopilotRecoveryFixtures
{
    public static function seed(User $owner, User $foreign): void
    {
        DB::table('copilot_controls')->insert([
            ['owner_id' => $owner->id, 'consent' => false, 'generation' => 2],
            ['owner_id' => $foreign->id, 'consent' => true, 'generation' => 1],
        ]);
        foreach (['complete', 'unavailable', 'rejected', 'pending'] as $status) {
            DB::table('copilot_requests')->insert(['owner_id' => $owner->id, 'reference' => (string) Str::uuid(),
                'lane' => 'provider', 'consent_generation' => 1, 'status' => $status, 'reserved_tokens' => 16384,
                'finished_at' => $status === 'pending' ? null : now('UTC')]);
        }
        foreach (['complete', 'unavailable', 'rejected', 'pending'] as $status) {
            DB::table('copilot_requests')->insert(['owner_id' => $owner->id, 'reference' => (string) Str::uuid(), 'lane' => 'answer', 'consent_generation' => 1, 'status' => $status, 'reserved_tokens' => 16384, 'finished_at' => $status === 'pending' ? null : now('UTC')]);
        }
        DB::table('copilot_requests')->insert(['owner_id' => $foreign->id, 'reference' => (string) Str::uuid(),
            'lane' => 'provider', 'consent_generation' => 1, 'reserved_tokens' => 16384]);
    }

    public static function read(User $owner): array
    {
        return ['control' => (array) DB::table('copilot_controls')->where('owner_id', $owner->id)->firstOrFail(),
            'requests' => DB::table('copilot_requests')->where('owner_id', $owner->id)->orderBy('id')->get()->all(),
            'reserved_tokens' => (int) DB::table('copilot_requests')->where('owner_id', $owner->id)->sum('reserved_tokens')];
    }

    public static function assertHeld(User $owner): void
    {
        $before = self::read($owner);
        $actor = Auth::user();
        $enabled = config('copilot.enabled');
        Auth::setUser($owner);
        config(['copilot.enabled' => false]);
        try {
            try {
                app(CopilotBoundary::class)->preview((string) Str::uuid(), ToolQuery::parse(['tool' => 'cash_availability', 'arguments' => []]));
                throw new \RuntimeException('Restored copilot request escaped disabled boundary');
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
            }
            if (json_encode(self::read($owner)) !== json_encode($before)) {
                throw new \RuntimeException('Restored copilot usage or consent changed');
            }
        } finally {
            config(['copilot.enabled' => $enabled]);
            if ($actor instanceof User) {
                Auth::setUser($actor);
            } else {
                Auth::forgetGuards();
            }
        }
    }
}
