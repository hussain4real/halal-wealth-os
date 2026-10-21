<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE dashboard_settings (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, version INTEGER NOT NULL CHECK(version>0),
 supersedes_id BIGINT UNIQUE, data JSONB NOT NULL CHECK(jsonb_typeof(data)='object'),
 reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), CONSTRAINT dashboard_settings_request UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES dashboard_settings(id,owner_id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX dashboard_settings_version ON dashboard_settings(owner_id,version);
CREATE TABLE dashboard_snapshots (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 evidence JSONB NOT NULL CHECK(jsonb_typeof(evidence)='object'), evidence_hash CHAR(64) NOT NULL,
 recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), CONSTRAINT dashboard_snapshot_request UNIQUE(owner_id,idempotency_key)
);
CREATE TABLE dashboard_dismissals (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, action_key CHAR(64) NOT NULL,
 basis JSONB NOT NULL CHECK(jsonb_typeof(basis)='object'), reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), CONSTRAINT dashboard_dismissal_episode UNIQUE(owner_id,action_key)
);
CREATE TABLE dashboard_dismissal_requests (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 dismissal_id BIGINT NOT NULL, recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT dashboard_dismissal_request UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(dismissal_id,owner_id) REFERENCES dashboard_dismissals(id,owner_id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION hwo_dashboard_settings_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior dashboard_settings%ROWTYPE;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hwo:posting:owner:'||NEW.owner_id::text,0));
 SELECT * INTO prior FROM dashboard_settings WHERE owner_id=NEW.owner_id ORDER BY version DESC LIMIT 1;
 IF (prior.id IS NULL AND (NEW.version<>1 OR NEW.supersedes_id IS NOT NULL))
 OR (prior.id IS NOT NULL AND (NEW.supersedes_id IS DISTINCT FROM prior.id OR NEW.version<>prior.version+1))
 THEN RAISE EXCEPTION 'Dashboard settings must retain the current owned predecessor'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER guard_dashboard_settings BEFORE INSERT ON dashboard_settings FOR EACH ROW EXECUTE FUNCTION hwo_dashboard_settings_guard();
SQL);
        foreach (['settings', 'snapshots', 'dismissals', 'dismissal_requests'] as $table) {
            DB::unprepared("CREATE TRIGGER retain_dashboard_$table BEFORE UPDATE OR DELETE ON dashboard_$table FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
    }

    public function down(): void
    {
        foreach (['settings', 'snapshots', 'dismissals', 'dismissal_requests'] as $table) {
            if (DB::table('dashboard_'.$table)->exists()) {
                throw new RuntimeException('Retained dashboard evidence requires a forward migration; downgrade refused before DDL.');
            }
        }
        DB::unprepared('DROP TABLE dashboard_dismissal_requests,dashboard_dismissals,dashboard_snapshots,dashboard_settings; DROP FUNCTION hwo_dashboard_settings_guard();');
    }
};
