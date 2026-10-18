<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE cash_availability_settings (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, version INTEGER NOT NULL CHECK(version>0),
 supersedes_id BIGINT UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 currency CHAR(3) NOT NULL CHECK(currency IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD')),
 buffer_minor BIGINT NOT NULL CHECK(buffer_minor>=0), next_income_date DATE NOT NULL,
 investment_target_minor BIGINT NOT NULL CHECK(investment_target_minor>=0),
 requirements JSONB NOT NULL CHECK(jsonb_typeof(requirements)='array'),
 overlap_links JSONB NOT NULL CHECK(jsonb_typeof(overlap_links)='array'),
 investment_goals JSONB NOT NULL CHECK(jsonb_typeof(investment_goals)='array'),
 reason VARCHAR(180) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,version), UNIQUE(owner_id,idempotency_key),
 CHECK(next_income_date BETWEEN DATE '0001-01-01' AND DATE '9998-12-31'),
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES cash_availability_settings(id,owner_id) ON DELETE RESTRICT
);
CREATE INDEX cash_availability_settings_owner ON cash_availability_settings(owner_id,id DESC);
CREATE OR REPLACE FUNCTION hwo_cash_settings_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior RECORD; item JSONB;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hwo:posting:owner:'||NEW.owner_id::text,0));
 SELECT * INTO prior FROM cash_availability_settings WHERE owner_id=NEW.owner_id ORDER BY version DESC LIMIT 1;
 IF (prior.id IS NULL AND (NEW.supersedes_id IS NOT NULL OR NEW.version<>1))
 OR (prior.id IS NOT NULL AND (NEW.supersedes_id IS DISTINCT FROM prior.id OR NEW.version<>prior.version+1))
 THEN RAISE EXCEPTION 'Cash settings require the current owned prior version'; END IF;
 FOR item IN SELECT value FROM jsonb_array_elements(NEW.requirements) LOOP
  IF NOT EXISTS(SELECT 1 FROM planning_schedules s JOIN planning_categories c ON c.id=s.category_id
    WHERE s.id=(item->>'schedule_id')::bigint AND s.owner_id=NEW.owner_id
    AND s.family_reference=item->>'family' AND c.direction='expense')
  THEN RAISE EXCEPTION 'Required classification must identify an owned expense schedule'; END IF;
 END LOOP;
 FOR item IN SELECT value FROM jsonb_array_elements(NEW.investment_goals) LOOP
  IF NOT EXISTS(SELECT 1 FROM goal_plans g WHERE g.id=(item#>>'{}')::bigint AND g.owner_id=NEW.owner_id
    AND g.supersedes_id IS NULL AND g.mode='reserved')
  THEN RAISE EXCEPTION 'Investment membership requires owned reserved goal roots'; END IF;
 END LOOP;
 FOR item IN SELECT value FROM jsonb_array_elements(NEW.overlap_links) LOOP
  IF NOT EXISTS(SELECT 1 FROM goal_reservations r JOIN goal_plans g ON g.id=r.goal_id
    JOIN planning_schedules s ON s.id=(item->>'schedule_id')::bigint
    WHERE r.id=(item->>'reservation_id')::bigint AND r.owner_id=NEW.owner_id AND s.owner_id=NEW.owner_id AND s.currency=g.currency)
  THEN RAISE EXCEPTION 'Overlap requires owned matching native identities'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE TRIGGER guard_cash_availability_settings BEFORE INSERT ON cash_availability_settings FOR EACH ROW EXECUTE FUNCTION hwo_cash_settings_guard();
CREATE TRIGGER retain_cash_availability_settings BEFORE UPDATE OR DELETE ON cash_availability_settings FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
SQL);
    }

    public function down(): void
    {
        if (DB::table('cash_availability_settings')->exists()) {
            throw new RuntimeException('Retained availability settings require a forward migration; downgrade refused before DDL.');
        }
        DB::unprepared('DROP TABLE cash_availability_settings; DROP FUNCTION hwo_cash_settings_guard();');
    }
};
