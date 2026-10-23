<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['portfolio_prices', 'fx_observations'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ALTER COLUMN observed_at DROP NOT NULL, ALTER COLUMN effective_at DROP NOT NULL,
ADD COLUMN source_date DATE, ADD COLUMN source_timezone VARCHAR(64), ADD COLUMN received_at TIMESTAMPTZ,
ADD COLUMN eligible_at TIMESTAMPTZ, ADD COLUMN age_from TIMESTAMPTZ, ADD COLUMN time_policy VARCHAR(32),
ADD CONSTRAINT {$table}_time_variant CHECK (COALESCE((
(source_date IS NULL AND source_timezone IS NULL AND observed_at IS NOT NULL AND effective_at IS NOT NULL
 AND ((time_policy IS NULL AND received_at IS NULL AND eligible_at IS NULL AND age_from IS NULL)
 OR (time_policy='provider-instant-v1' AND received_at IS NOT NULL AND eligible_at=effective_at AND age_from=observed_at AND observed_at<=received_at)))
OR (time_policy='daily-closed-v1' AND source_date IS NOT NULL AND source_timezone IS NOT NULL
 AND observed_at IS NULL AND effective_at IS NULL AND received_at IS NOT NULL AND eligible_at IS NOT NULL AND age_from IS NOT NULL
 AND age_from<eligible_at AND eligible_at<=received_at)),FALSE))");
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE market_mappings (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 request_payload JSONB NOT NULL, kind VARCHAR(8) NOT NULL CHECK(kind IN ('price','fx')),
 feed JSONB NOT NULL, target JSONB NOT NULL, enabled BOOLEAN NOT NULL,
 supersedes_id BIGINT UNIQUE, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES market_mappings(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE market_attempts (
 id BIGSERIAL PRIMARY KEY, provider VARCHAR(24) NOT NULL CHECK(provider='twelve_data'),
 feed_key CHAR(64) NOT NULL, feed JSONB NOT NULL, refresh_window DATE NOT NULL,
 ordinal SMALLINT NOT NULL CHECK(ordinal BETWEEN 1 AND 3), credits SMALLINT NOT NULL CHECK(credits=1),
 claimed_at TIMESTAMPTZ NOT NULL, deadline TIMESTAMPTZ NOT NULL,
 CHECK(deadline>claimed_at), UNIQUE(provider,feed_key,refresh_window,ordinal)
);
CREATE INDEX market_quota ON market_attempts(provider,claimed_at);
CREATE TABLE market_results (
 id BIGSERIAL PRIMARY KEY, attempt_id BIGINT NOT NULL UNIQUE REFERENCES market_attempts(id) ON DELETE RESTRICT,
 received_at TIMESTAMPTZ NOT NULL, status VARCHAR(12) NOT NULL CHECK(status IN ('accepted','retryable','rejected','late')),
 safe_code VARCHAR(48) NOT NULL, retry_after INTEGER NOT NULL CHECK(retry_after BETWEEN 0 AND 604800),
 sample JSONB, winner_key CHAR(64) UNIQUE,
 CHECK((status='accepted')=(sample IS NOT NULL)), CHECK((winner_key IS NOT NULL)=(status='accepted'))
);
CREATE TABLE market_admissions (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 mapping_id BIGINT NOT NULL, result_id BIGINT NOT NULL REFERENCES market_results(id) ON DELETE RESTRICT,
 target_key CHAR(64) NOT NULL, sample_key CHAR(64) NOT NULL,
 outcome VARCHAR(12) NOT NULL CHECK(outcome IN ('accepted','rejected')), safe_code VARCHAR(48) NOT NULL,
 price_id BIGINT REFERENCES portfolio_prices(id) ON DELETE RESTRICT,
 fx_id BIGINT REFERENCES fx_observations(id) ON DELETE RESTRICT,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK((outcome='accepted' AND num_nonnulls(price_id,fx_id)=1) OR (outcome='rejected' AND num_nonnulls(price_id,fx_id)=0)), UNIQUE(id,owner_id),
 UNIQUE(owner_id,mapping_id,result_id),
 FOREIGN KEY(mapping_id,owner_id) REFERENCES market_mappings(id,owner_id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX market_unique_observation ON market_admissions(owner_id,target_key,sample_key) WHERE outcome='accepted';
CREATE INDEX market_mapping_owner ON market_mappings(owner_id,id);
CREATE INDEX market_admission_owner ON market_admissions(owner_id,id);
CREATE OR REPLACE FUNCTION hwo_market_admission_owner() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.price_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM portfolio_prices WHERE id=NEW.price_id AND owner_id=NEW.owner_id)
 OR NEW.fx_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM fx_observations WHERE id=NEW.fx_id AND owner_id=NEW.owner_id AND purpose='reporting')
 THEN RAISE EXCEPTION 'Market admission requires an owned reporting observation'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER check_market_admission_owner BEFORE INSERT ON market_admissions FOR EACH ROW EXECUTE FUNCTION hwo_market_admission_owner();
SQL);
        foreach (['mappings', 'attempts', 'results', 'admissions'] as $table) {
            DB::statement("CREATE TRIGGER retain_market_{$table} BEFORE UPDATE OR DELETE ON market_{$table} FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
    }

    public function down(): void
    {
        foreach (['market_mappings', 'market_attempts', 'market_results', 'market_admissions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retained market refresh evidence: downgrade refused before DDL.');
            }
        }
        foreach (['portfolio_prices', 'fx_observations'] as $table) {
            if (DB::table($table)->whereNotNull('received_at')->exists()) {
                throw new RuntimeException('Retained provider observation: downgrade refused before DDL.');
            }
        }
        DB::unprepared('DROP TABLE market_admissions,market_results,market_attempts,market_mappings; DROP FUNCTION hwo_market_admission_owner();');
        foreach (['portfolio_prices', 'fx_observations'] as $table) {
            DB::unprepared("ALTER TABLE {$table} DROP CONSTRAINT {$table}_time_variant,
DROP COLUMN source_date, DROP COLUMN source_timezone, DROP COLUMN received_at, DROP COLUMN eligible_at,
DROP COLUMN age_from, DROP COLUMN time_policy, ALTER COLUMN observed_at SET NOT NULL, ALTER COLUMN effective_at SET NOT NULL");
        }
    }
};
