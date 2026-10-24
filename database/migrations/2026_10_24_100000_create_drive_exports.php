<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE drive_controls (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL UNIQUE REFERENCES users(id) ON DELETE RESTRICT,
 generation CHAR(32) NOT NULL, stopped BOOLEAN NOT NULL DEFAULT TRUE, active_id BIGINT,
 oauth_hash CHAR(64), session_hash CHAR(64), oauth_verifier TEXT, oauth_expires TIMESTAMPTZ,
 review JSONB, review_hash CHAR(64), review_expires TIMESTAMPTZ, UNIQUE(id,owner_id)
);
CREATE TABLE drive_connections (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, subject VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL,
 epoch CHAR(32) NOT NULL, generation CHAR(32) NOT NULL,
 status VARCHAR(24) NOT NULL CHECK(status IN ('account_review','ready','reconnect','disconnected')),
 folder_id VARCHAR(128), folder_name VARCHAR(120),
 revoke_state VARCHAR(24) NOT NULL DEFAULT 'not_requested' CHECK(revoke_state IN ('not_requested','pending','confirmed','unconfirmed')),
 revoke_attempts SMALLINT NOT NULL DEFAULT 0 CHECK(revoke_attempts BETWEEN 0 AND 3), revoke_lease_until TIMESTAMPTZ,
 recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id)
);
ALTER TABLE drive_controls ADD FOREIGN KEY(active_id,owner_id) REFERENCES drive_connections(id,owner_id) ON DELETE RESTRICT;
CREATE TABLE drive_secrets (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 connection_id BIGINT NOT NULL UNIQUE, ciphertext TEXT NOT NULL CHECK(octet_length(ciphertext)<=24576),
 FOREIGN KEY(connection_id,owner_id) REFERENCES drive_connections(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE drive_operations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 kind VARCHAR(8) NOT NULL CHECK(kind IN ('folder','report')), connection_id BIGINT NOT NULL,
 generation CHAR(32) NOT NULL, epoch CHAR(32) NOT NULL, snapshot_id BIGINT,
 content_hash CHAR(64) NOT NULL, content_bytes INTEGER NOT NULL CHECK(content_bytes BETWEEN 0 AND 5000000),
 target_id VARCHAR(128) NOT NULL, target_hash CHAR(64) NOT NULL,
 remote_id VARCHAR(128) NOT NULL, deadline TIMESTAMPTZ NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','claimed','uploaded','unknown','failed','conflict','reconnect','cancelled','expired')),
 attempts SMALLINT NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 3), lease CHAR(32), next_at TIMESTAMPTZ,
 safe_code VARCHAR(40) NOT NULL DEFAULT 'queued', recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,idempotency_key), UNIQUE(remote_id),
 FOREIGN KEY(connection_id,owner_id) REFERENCES drive_connections(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(snapshot_id,owner_id) REFERENCES dashboard_snapshots(id,owner_id) ON DELETE RESTRICT,
 CHECK((kind='report')=(snapshot_id IS NOT NULL))
);
CREATE INDEX drive_pending ON drive_operations(state,next_at,id);
CREATE TABLE drive_events (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 connection_id BIGINT NOT NULL, operation_id BIGINT, ordinal SMALLINT NOT NULL CHECK(ordinal BETWEEN 1 AND 8),
 safe_code VARCHAR(40) NOT NULL, recorded_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE NULLS NOT DISTINCT(owner_id,connection_id,operation_id,ordinal),
 FOREIGN KEY(connection_id,owner_id) REFERENCES drive_connections(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(operation_id,owner_id) REFERENCES drive_operations(id,owner_id) ON DELETE RESTRICT
);
CREATE TRIGGER retain_drive_event BEFORE UPDATE OR DELETE ON drive_events FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE OR REPLACE FUNCTION hwo_drive_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Retained Drive identity'; END IF;
 IF TG_TABLE_NAME='drive_operations' AND
 (to_jsonb(NEW)-ARRAY['state','attempts','lease','next_at','safe_code']) IS DISTINCT FROM
 (to_jsonb(OLD)-ARRAY['state','attempts','lease','next_at','safe_code']) THEN RAISE EXCEPTION 'Retained Drive request'; END IF;
 IF TG_TABLE_NAME='drive_connections' AND
 (to_jsonb(NEW)-ARRAY['status','folder_id','folder_name','revoke_state','revoke_attempts','revoke_lease_until']) IS DISTINCT FROM
 (to_jsonb(OLD)-ARRAY['status','folder_id','folder_name','revoke_state','revoke_attempts','revoke_lease_until']) THEN RAISE EXCEPTION 'Retained Drive account'; END IF;
 IF TG_TABLE_NAME='drive_connections' THEN
  IF OLD.folder_id IS NOT NULL AND (NEW.folder_id IS DISTINCT FROM OLD.folder_id OR NEW.folder_name IS DISTINCT FROM OLD.folder_name) THEN RAISE EXCEPTION 'Retained Drive destination'; END IF;
 END IF;
 IF NEW.id<>OLD.id OR NEW.owner_id<>OLD.owner_id THEN RAISE EXCEPTION 'Retained Drive owner'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER retain_drive_operation BEFORE UPDATE OR DELETE ON drive_operations FOR EACH ROW EXECUTE FUNCTION hwo_drive_guard();
CREATE TRIGGER retain_drive_connection BEFORE UPDATE OR DELETE ON drive_connections FOR EACH ROW EXECUTE FUNCTION hwo_drive_guard();
CREATE TRIGGER retain_drive_control BEFORE UPDATE OR DELETE ON drive_controls FOR EACH ROW EXECUTE FUNCTION hwo_drive_guard();
SQL);
    }

    public function down(): void
    {
        foreach (['controls', 'connections', 'secrets', 'operations', 'events'] as $name) {
            if (DB::table('drive_'.$name)->exists()) {
                throw new RuntimeException('Retained Drive evidence: downgrade refused before DDL.');
            }
        }
        DB::unprepared('ALTER TABLE drive_controls DROP CONSTRAINT drive_controls_active_id_owner_id_fkey; DROP TABLE drive_events,drive_operations,drive_secrets,drive_connections,drive_controls; DROP FUNCTION hwo_drive_guard();');
    }
};
