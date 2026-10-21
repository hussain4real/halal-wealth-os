<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE decision_opportunities (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL, CHECK(actor_id=owner_id),
 reference CHAR(26) NOT NULL UNIQUE, series_id CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version>0),
 supersedes_id BIGINT UNIQUE REFERENCES decision_opportunities(id) ON DELETE RESTRICT,
 name VARCHAR(180) NOT NULL CHECK(length(trim(name))>0), status VARCHAR(12) NOT NULL CHECK(status IN ('watchlist','planned','closed')),
 asset_id BIGINT REFERENCES portfolio_assets(id) ON DELETE RESTRICT,
 currency CHAR(3) NOT NULL CHECK(currency IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD')),
 target_minor BIGINT CHECK(target_minor>0), maximum_minor BIGINT CHECK(maximum_minor>0),
 CHECK(maximum_minor IS NULL OR target_minor IS NULL OR target_minor<=maximum_minor),
 CHECK(status<>'planned' OR target_minor IS NOT NULL),
 tranches JSONB NOT NULL CHECK(jsonb_typeof(tranches)='array' AND jsonb_array_length(tranches)<=24),
 attributes JSONB NOT NULL CHECK(jsonb_typeof(attributes)='object'),
 reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, recorded_at TIMESTAMPTZ NOT NULL,
 UNIQUE(owner_id,idempotency_key), UNIQUE(series_id,version), UNIQUE(id,owner_id,currency)
);
CREATE INDEX decision_owner_history ON decision_opportunities(owner_id,id);
CREATE TABLE decision_reservations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL, CHECK(actor_id=owner_id),
 reference CHAR(26) NOT NULL UNIQUE, opportunity_id BIGINT NOT NULL, series_id CHAR(26) NOT NULL,
 tranche_key VARCHAR(60) NOT NULL, currency CHAR(3) NOT NULL,
 reservation_id BIGINT NOT NULL UNIQUE REFERENCES goal_reservations(id) ON DELETE RESTRICT,
 reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, recorded_at TIMESTAMPTZ NOT NULL,
 UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(opportunity_id,owner_id,currency) REFERENCES decision_opportunities(id,owner_id,currency) ON DELETE RESTRICT
);
CREATE INDEX decision_reservations_series ON decision_reservations(owner_id,series_id,id);
CREATE TABLE decision_event_links (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL, CHECK(actor_id=owner_id),
 reference CHAR(26) NOT NULL UNIQUE, opportunity_id BIGINT NOT NULL, series_id CHAR(26) NOT NULL,
 tranche_key VARCHAR(60) NOT NULL, currency CHAR(3) NOT NULL,
 event_id BIGINT NOT NULL UNIQUE REFERENCES investment_events(id) ON DELETE RESTRICT,
 reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, recorded_at TIMESTAMPTZ NOT NULL,
 UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(opportunity_id,owner_id,currency) REFERENCES decision_opportunities(id,owner_id,currency) ON DELETE RESTRICT
);
CREATE INDEX decision_events_series ON decision_event_links(owner_id,series_id,id);
CREATE OR REPLACE FUNCTION hwo_decision_lock() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hwo:posting:owner:'||NEW.owner_id::text,0));
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION hwo_decision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE plan_row RECORD; prior_row RECORD; reservation_row RECORD; event_row RECORD; claim RECORD; total NUMERIC; reserved NUMERIC; spent NUMERIC; limit_minor NUMERIC;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hwo:posting:owner:'||NEW.owner_id::text,0));
 IF NEW.actor_id<>NEW.owner_id OR NEW.actor_is_admin IS DISTINCT FROM (SELECT is_admin FROM users WHERE id=NEW.owner_id) THEN RAISE EXCEPTION 'Retain actual owner actor attribution'; END IF;
 IF TG_TABLE_NAME='decision_opportunities' THEN
  IF NEW.asset_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM portfolio_assets WHERE id=NEW.asset_id AND owner_id=NEW.owner_id AND currency=NEW.currency) THEN RAISE EXCEPTION 'Decision requires an owned native asset reference'; END IF;
  IF NEW.supersedes_id IS NULL THEN
   IF NEW.version<>1 OR NEW.series_id<>NEW.reference THEN RAISE EXCEPTION 'Decision root starts at version one'; END IF;
  ELSE
   SELECT * INTO STRICT prior_row FROM decision_opportunities WHERE id=NEW.supersedes_id AND owner_id=NEW.owner_id;
   IF prior_row.status='closed' OR NEW.series_id<>prior_row.series_id OR NEW.version<>prior_row.version+1 OR NEW.currency<>prior_row.currency
    OR (prior_row.asset_id IS NOT NULL AND NEW.asset_id IS DISTINCT FROM prior_row.asset_id) THEN RAISE EXCEPTION 'Decision revision preserves identity and closed history'; END IF;
  END IF;
  IF EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.tranches) t WHERE jsonb_typeof(t)<>'object' OR coalesce(t->>'key','') !~ '^[a-zA-Z0-9_-]{1,60}$'
   OR coalesce(t->>'amount_minor','') !~ '^[1-9][0-9]*$' OR (t->>'amount_minor')::numeric>9223372036854775807 OR length(trim(coalesce(t->>'label','')))=0 OR length(trim(coalesce(t->>'condition','')))=0)
   OR (SELECT count(*)<>count(DISTINCT t->>'key') FROM jsonb_array_elements(NEW.tranches) t) THEN RAISE EXCEPTION 'Invalid conditional tranche list'; END IF;
  SELECT coalesce(sum((t->>'amount_minor')::numeric),0) INTO total FROM jsonb_array_elements(NEW.tranches) t;
  IF (NEW.target_minor IS NULL AND jsonb_array_length(NEW.tranches)<>0) OR (NEW.target_minor IS NOT NULL AND total<>NEW.target_minor) THEN RAISE EXCEPTION 'Tranches reconcile to exact target'; END IF;
  FOR claim IN SELECT DISTINCT d.tranche_key FROM decision_reservations d JOIN goal_reservations r ON r.id=d.reservation_id
    WHERE d.owner_id=NEW.owner_id AND d.series_id=NEW.series_id AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id)
    UNION SELECT d.tranche_key FROM decision_event_links d JOIN investment_events e ON e.id=d.event_id
    WHERE d.owner_id=NEW.owner_id AND d.series_id=NEW.series_id AND NOT EXISTS(SELECT 1 FROM investment_events rev WHERE rev.reverses_id=e.id)
  LOOP
   SELECT (x->>'amount_minor')::numeric INTO limit_minor FROM jsonb_array_elements(NEW.tranches) x WHERE x->>'key'=claim.tranche_key;
   SELECT coalesce(sum(r.amount_minor::numeric),0) INTO reserved FROM decision_reservations d JOIN goal_reservations r ON r.id=d.reservation_id
    WHERE d.owner_id=NEW.owner_id AND d.series_id=NEW.series_id AND d.tranche_key=claim.tranche_key AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id);
   SELECT coalesce(sum(e.amount_minor::numeric+e.fee_minor::numeric+e.tax_minor::numeric),0) INTO spent FROM decision_event_links d JOIN investment_events e ON e.id=d.event_id
    WHERE d.owner_id=NEW.owner_id AND d.series_id=NEW.series_id AND d.tranche_key=claim.tranche_key AND NOT EXISTS(SELECT 1 FROM investment_events rev WHERE rev.reverses_id=e.id);
   IF limit_minor IS NULL OR limit_minor<reserved+spent OR (NEW.status='closed' AND reserved>0) THEN RAISE EXCEPTION 'Revision retains active reservations and actual attribution'; END IF;
  END LOOP;
 ELSE
  SELECT * INTO STRICT plan_row FROM decision_opportunities WHERE id=NEW.opportunity_id AND owner_id=NEW.owner_id;
  IF NEW.series_id<>plan_row.series_id OR NEW.currency<>plan_row.currency OR plan_row.status='closed' OR EXISTS(SELECT 1 FROM decision_opportunities n WHERE n.supersedes_id=plan_row.id)
   OR NOT EXISTS(SELECT 1 FROM jsonb_array_elements(plan_row.tranches) t WHERE t->>'key'=NEW.tranche_key) THEN RAISE EXCEPTION 'Use current owned plan and retained tranche'; END IF;
  IF TG_TABLE_NAME='decision_reservations' THEN
   SELECT * INTO STRICT reservation_row FROM goal_reservations WHERE id=NEW.reservation_id AND owner_id=NEW.owner_id;
   IF EXISTS(SELECT 1 FROM goal_releases WHERE reservation_id=reservation_row.id) OR NOT EXISTS(SELECT 1 FROM goal_plans g WHERE g.id=reservation_row.goal_id AND g.owner_id=NEW.owner_id AND g.currency=NEW.currency AND g.mode='reserved') THEN RAISE EXCEPTION 'Decision requires active native reservation'; END IF;
   SELECT (t->>'amount_minor')::numeric INTO limit_minor FROM jsonb_array_elements(plan_row.tranches) t WHERE t->>'key'=NEW.tranche_key;
   SELECT coalesce(sum(r.amount_minor::numeric),0) INTO reserved FROM decision_reservations d JOIN goal_reservations r ON r.id=d.reservation_id
    WHERE d.owner_id=NEW.owner_id AND d.series_id=NEW.series_id AND d.tranche_key=NEW.tranche_key AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id);
   SELECT coalesce(sum(e.amount_minor::numeric+e.fee_minor::numeric+e.tax_minor::numeric),0) INTO spent FROM decision_event_links d JOIN investment_events e ON e.id=d.event_id
    WHERE d.owner_id=NEW.owner_id AND d.series_id=NEW.series_id AND d.tranche_key=NEW.tranche_key AND NOT EXISTS(SELECT 1 FROM investment_events rev WHERE rev.reverses_id=e.id);
   IF reserved+spent>limit_minor THEN RAISE EXCEPTION 'Tranche capacity exceeded'; END IF;
  ELSE
   SELECT * INTO STRICT event_row FROM investment_events WHERE id=NEW.event_id AND owner_id=NEW.owner_id;
   IF event_row.asset_id IS DISTINCT FROM plan_row.asset_id OR event_row.currency<>plan_row.currency OR event_row.kind NOT IN ('buy','ipo_pay') OR event_row.amount_minor<=0 OR event_row.reverses_id IS NOT NULL
    OR EXISTS(SELECT 1 FROM investment_events rev WHERE rev.reverses_id=event_row.id) THEN RAISE EXCEPTION 'Link current factual purchase or paid subscription for the exact reference'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['opportunities', 'reservations', 'event_links'] as $table) {
            DB::unprepared("CREATE TRIGGER lock_decision_$table BEFORE INSERT ON decision_$table FOR EACH ROW EXECUTE FUNCTION hwo_decision_lock(); CREATE TRIGGER guard_decision_$table AFTER INSERT ON decision_$table FOR EACH ROW EXECUTE FUNCTION hwo_decision_guard(); CREATE TRIGGER retain_decision_$table BEFORE UPDATE OR DELETE ON decision_$table FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
    }

    public function down(): void
    {
        foreach (['opportunities', 'reservations', 'event_links'] as $table) {
            if (DB::table('decision_'.$table)->exists()) {
                throw new RuntimeException('Retained decision history requires a forward migration; downgrade refused before DDL.');
            }
        }
        DB::unprepared('DROP TABLE decision_event_links,decision_reservations,decision_opportunities; DROP FUNCTION hwo_decision_guard(); DROP FUNCTION hwo_decision_lock();');
    }
};
