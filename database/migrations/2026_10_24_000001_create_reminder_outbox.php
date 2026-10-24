<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE reminder_preferences (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 version INTEGER NOT NULL CHECK(version BETWEEN 1 AND 128), supersedes_id BIGINT UNIQUE, data JSONB NOT NULL,
 recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,idempotency_key), UNIQUE(owner_id,version),
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES reminder_preferences(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE reminder_controls (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
 external_paused BOOLEAN NOT NULL DEFAULT TRUE, generation CHAR(32) NOT NULL, UNIQUE(id,owner_id)
);
CREATE TABLE reminder_notifications (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 episode_key CHAR(64) NOT NULL, rule VARCHAR(24) NOT NULL, source_key VARCHAR(120) NOT NULL,
 due_date DATE, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,episode_key)
);
CREATE TABLE reminder_channels (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 notification_id BIGINT NOT NULL, channel VARCHAR(8) NOT NULL CHECK(channel IN ('in_app','email')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,notification_id,channel),
 FOREIGN KEY(notification_id,owner_id) REFERENCES reminder_notifications(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE reminder_claims (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 channel_id BIGINT NOT NULL, ordinal SMALLINT NOT NULL CHECK(ordinal BETWEEN 1 AND 3),
 permit_hash CHAR(64) NOT NULL, destination_hash CHAR(64) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,channel_id,ordinal),
 FOREIGN KEY(channel_id,owner_id) REFERENCES reminder_channels(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE reminder_results (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 claim_id BIGINT NOT NULL UNIQUE, preference_id BIGINT NOT NULL,
 outcome VARCHAR(12) NOT NULL CHECK(outcome IN ('in_app','mock_smtp','retryable','uncertain')),
 safe_code VARCHAR(32) NOT NULL, message_hash CHAR(64) NOT NULL,
 retry_at TIMESTAMPTZ, completed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK((outcome='retryable')=(retry_at IS NOT NULL)), UNIQUE(id,owner_id),
 FOREIGN KEY(claim_id,owner_id) REFERENCES reminder_claims(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(preference_id,owner_id) REFERENCES reminder_preferences(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE reminder_reads (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 notification_id BIGINT NOT NULL, read_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,notification_id),
 FOREIGN KEY(notification_id,owner_id) REFERENCES reminder_notifications(id,owner_id) ON DELETE RESTRICT
);
CREATE INDEX reminder_pending_owner ON reminder_channels(owner_id,id);
CREATE INDEX reminder_claim_channel ON reminder_claims(owner_id,channel_id,id);
SQL);
        foreach (['preferences', 'notifications', 'channels', 'claims', 'results', 'reads'] as $name) {
            DB::statement("CREATE TRIGGER retain_reminder_{$name} BEFORE UPDATE OR DELETE ON reminder_{$name} FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hwo_reminder_control_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR NEW.id<>OLD.id OR NEW.owner_id<>OLD.owner_id THEN
 RAISE EXCEPTION 'Retained reminder control identity'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER retain_reminder_control BEFORE UPDATE OR DELETE ON reminder_controls FOR EACH ROW EXECUTE FUNCTION hwo_reminder_control_guard();
SQL);
    }

    public function down(): void
    {
        foreach (['preferences', 'controls', 'notifications', 'channels', 'claims', 'results', 'reads'] as $name) {
            if (DB::table('reminder_'.$name)->exists()) {
                throw new RuntimeException('Retained reminder evidence: downgrade refused before DDL.');
            }
        }
        DB::unprepared('DROP TABLE reminder_reads,reminder_results,reminder_claims,reminder_channels,reminder_notifications,reminder_controls,reminder_preferences; DROP FUNCTION hwo_reminder_control_guard();');
    }
};
