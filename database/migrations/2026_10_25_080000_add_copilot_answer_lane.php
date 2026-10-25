<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE copilot_requests DROP CONSTRAINT copilot_requests_lane_check; ALTER TABLE copilot_requests ADD CONSTRAINT copilot_requests_lane_check CHECK(lane IN ('preview','provider','answer'))");
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::table('copilot_requests')->where('lane', 'answer')->exists()) {
                throw new RuntimeException('Retained copilot answers: downgrade refused before DDL.');
            }
            DB::unprepared("ALTER TABLE copilot_requests DROP CONSTRAINT copilot_requests_lane_check; ALTER TABLE copilot_requests ADD CONSTRAINT copilot_requests_lane_check CHECK(lane IN ('preview','provider'))");
        });
    }
};
