<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE purification_obligations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL, CHECK(actor_id=owner_id),
 reference CHAR(26) NOT NULL UNIQUE, series_id CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version>0),
 supersedes_id BIGINT UNIQUE REFERENCES purification_obligations(id) ON DELETE RESTRICT,
 distribution_id BIGINT NOT NULL REFERENCES investment_events(id) ON DELETE RESTRICT, distribution_lineage BIGINT NOT NULL,
 currency CHAR(3) NOT NULL, assessment_id BIGINT REFERENCES shariah_assessments(id) ON DELETE RESTRICT,
 method_reference VARCHAR(180) NOT NULL CHECK(length(trim(method_reference))>0), method_version VARCHAR(180), source_instruction VARCHAR(2000),
 base_kind VARCHAR(12) NOT NULL CHECK(base_kind IN ('gross','net','custom','missing')), base_minor BIGINT CHECK(base_minor>=0), base_explanation VARCHAR(180),
 calculation VARCHAR(12) NOT NULL CHECK(calculation IN ('percentage','fixed','missing')),
 rate_percent NUMERIC CHECK(rate_percent>=0 AND rate_percent<1000000000000000000 AND scale(rate_percent)<=12),
 fixed_minor BIGINT CHECK(fixed_minor>=0), due_minor BIGINT CHECK(due_minor>=0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0), recorded_at TIMESTAMPTZ NOT NULL,
 UNIQUE(owner_id,idempotency_key), UNIQUE(series_id,version), UNIQUE(id,owner_id,currency)
);
CREATE UNIQUE INDEX purification_business_identity ON purification_obligations(owner_id,distribution_lineage,method_reference) WHERE supersedes_id IS NULL;
CREATE INDEX purification_owner_history ON purification_obligations(owner_id,id);
CREATE TABLE purification_payments (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL, CHECK(actor_id=owner_id),
 reference CHAR(26) NOT NULL UNIQUE, payment_reference VARCHAR(180) NOT NULL CHECK(length(trim(payment_reference))>0),
 journal_id BIGINT NOT NULL UNIQUE REFERENCES journal_entries(id) ON DELETE RESTRICT,
 account_id BIGINT NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT, currency CHAR(3) NOT NULL, amount_minor BIGINT NOT NULL CHECK(amount_minor>0),
 evidence VARCHAR(180) NOT NULL CHECK(length(trim(evidence))>0), reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, recorded_at TIMESTAMPTZ NOT NULL,
 UNIQUE(owner_id,payment_reference), UNIQUE(owner_id,idempotency_key), UNIQUE(id,owner_id,currency)
);
CREATE TABLE purification_allocations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL, CHECK(actor_id=owner_id),
 reference CHAR(26) NOT NULL UNIQUE, obligation_id BIGINT NOT NULL, payment_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 amount_minor BIGINT NOT NULL CHECK(amount_minor>0), reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, recorded_at TIMESTAMPTZ NOT NULL,
 UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(obligation_id,owner_id,currency) REFERENCES purification_obligations(id,owner_id,currency) ON DELETE RESTRICT,
 FOREIGN KEY(payment_id,owner_id,currency) REFERENCES purification_payments(id,owner_id,currency) ON DELETE RESTRICT
);
CREATE TABLE purification_releases (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL, CHECK(actor_id=owner_id),
 reference CHAR(26) NOT NULL UNIQUE, allocation_id BIGINT NOT NULL UNIQUE REFERENCES purification_allocations(id) ON DELETE RESTRICT,
 reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, recorded_at TIMESTAMPTZ NOT NULL,
 UNIQUE(owner_id,idempotency_key)
);
CREATE OR REPLACE FUNCTION hwo_purification_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d RECORD; a RECORD; p RECORD; j RECORD; prior RECORD; known BOOLEAN; expected NUMERIC; numerator NUMERIC; used NUMERIC; paid NUMERIC;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hwo:posting:owner:'||NEW.owner_id,0));
 IF NEW.actor_id<>NEW.owner_id OR NEW.actor_is_admin IS DISTINCT FROM (SELECT is_admin FROM users WHERE id=NEW.owner_id)
 THEN RAISE EXCEPTION 'Purification requires the owned actor attribution'; END IF;
 IF TG_TABLE_NAME='purification_obligations' THEN
  SELECT * INTO d FROM investment_events WHERE id=NEW.distribution_id;
  IF d.id IS NULL OR d.owner_id<>NEW.owner_id OR d.kind<>'dividend' OR d.currency<>NEW.currency OR d.sort_id<>NEW.distribution_lineage
   OR EXISTS(SELECT 1 FROM investment_events WHERE reverses_id=d.id) THEN RAISE EXCEPTION 'Purification requires a current owned native distribution'; END IF;
  SELECT * INTO a FROM shariah_assessments WHERE id=NEW.assessment_id;
  IF NEW.assessment_id IS NOT NULL THEN
   IF a.owner_id<>NEW.owner_id OR a.asset_id<>d.asset_id OR a.provenance<>'source_claim' THEN RAISE EXCEPTION 'Assessment must attribute the exact owned distribution asset'; END IF;
  END IF;
  IF NEW.supersedes_id IS NULL THEN
   IF NEW.version<>1 THEN RAISE EXCEPTION 'Initial purification version must be one'; END IF;
  ELSE
   SELECT * INTO prior FROM purification_obligations WHERE id=NEW.supersedes_id FOR UPDATE;
   IF prior.owner_id<>NEW.owner_id OR prior.distribution_lineage<>NEW.distribution_lineage OR prior.currency<>NEW.currency
    OR prior.series_id<>NEW.series_id OR prior.method_reference<>NEW.method_reference OR NEW.version<>prior.version+1 OR NEW.recorded_at<prior.recorded_at
    THEN RAISE EXCEPTION 'Purification corrections retain distribution lineage method and ordered attribution'; END IF;
  END IF;
  IF (NEW.base_kind='missing' AND NEW.base_minor IS NOT NULL) OR (NEW.base_kind='gross' AND NEW.base_minor IS DISTINCT FROM d.amount_minor)
   OR (NEW.base_kind='net' AND NEW.base_minor::numeric IS DISTINCT FROM d.amount_minor::numeric-d.fee_minor-d.tax_minor)
   OR (NEW.base_kind='custom' AND NEW.base_minor IS NOT NULL AND coalesce(length(trim(NEW.base_explanation)),0)=0)
   OR (NEW.calculation<>'percentage' AND NEW.rate_percent IS NOT NULL) OR (NEW.calculation<>'fixed' AND NEW.fixed_minor IS NOT NULL)
   THEN RAISE EXCEPTION 'Purification basis and calculation must preserve their exact source'; END IF;
  known:=NEW.assessment_id IS NOT NULL AND coalesce(jsonb_array_length(a.evidence_refs),0)>0 AND length(trim(a.methodology_version))>0
   AND coalesce(length(trim(NEW.source_instruction)),0)>0 AND coalesce(length(trim(NEW.method_version)),0)>0 AND NEW.base_minor IS NOT NULL
   AND ((NEW.calculation='percentage' AND NEW.rate_percent IS NOT NULL) OR (NEW.calculation='fixed' AND NEW.fixed_minor IS NOT NULL));
  expected:=NULL;
  IF known THEN
   IF NEW.calculation='fixed' THEN expected:=NEW.fixed_minor;
   ELSE
    numerator:=NEW.base_minor::numeric*(NEW.rate_percent*1000000000000);
    expected:=div(numerator,100000000000000)+CASE WHEN mod(numerator,100000000000000)*2>=100000000000000 THEN 1 ELSE 0 END;
   END IF;
  END IF;
  IF NEW.due_minor::numeric IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Purification amount must retain exact source computation or pending uncertainty'; END IF;
 ELSIF TG_TABLE_NAME='purification_payments' THEN
  SELECT * INTO j FROM journal_entries WHERE id=NEW.journal_id;
  SELECT * INTO a FROM accounts WHERE id=NEW.account_id;
  IF j.id IS NULL OR j.owner_id<>NEW.owner_id OR j.kind<>'expense' OR j.status<>'posted' OR j.reverses_journal_id IS NOT NULL
   OR a.owner_id<>NEW.owner_id OR a.currency_code<>NEW.currency OR a.system_role IS NOT NULL
   OR EXISTS(SELECT 1 FROM investment_event_journals WHERE journal_id=j.id) OR EXISTS(SELECT 1 FROM fx_operation_journals WHERE journal_id=j.id)
   OR (SELECT count(*) FROM postings WHERE journal_id=j.id)<>2
   OR NOT EXISTS(SELECT 1 FROM postings WHERE journal_id=j.id AND account_id=NEW.account_id AND owner_id=NEW.owner_id AND currency_code=NEW.currency AND amount_minor::numeric=-NEW.amount_minor::numeric)
   OR NOT EXISTS(SELECT 1 FROM postings x JOIN accounts c ON c.id=x.account_id WHERE x.journal_id=j.id AND x.owner_id=NEW.owner_id AND c.owner_id=NEW.owner_id AND c.system_role='expense' AND x.currency_code=NEW.currency AND x.amount_minor=NEW.amount_minor)
   THEN RAISE EXCEPTION 'Charity attribution requires an actual ungrouped owned native expense debit'; END IF;
 ELSIF TG_TABLE_NAME='purification_allocations' THEN
  SELECT * INTO a FROM purification_obligations WHERE id=NEW.obligation_id;
  SELECT * INTO p FROM purification_payments WHERE id=NEW.payment_id;
  SELECT * INTO j FROM journal_entries WHERE id=p.journal_id;
  IF a.due_minor IS NULL OR a.owner_id<>NEW.owner_id OR p.owner_id<>NEW.owner_id OR a.currency<>NEW.currency OR p.currency<>NEW.currency
   OR j.status<>'posted' OR EXISTS(SELECT 1 FROM purification_obligations WHERE supersedes_id=a.id)
   OR EXISTS(SELECT 1 FROM investment_events WHERE reverses_id=a.distribution_id)
   THEN RAISE EXCEPTION 'Allocation requires current known obligation and live owned same-currency payment'; END IF;
  SELECT coalesce(sum(x.amount_minor::numeric),0) INTO used FROM purification_allocations x WHERE x.payment_id=p.id AND NOT EXISTS(SELECT 1 FROM purification_releases y WHERE y.allocation_id=x.id);
  SELECT coalesce(sum(x.amount_minor::numeric),0) INTO paid FROM purification_allocations x JOIN purification_obligations o ON o.id=x.obligation_id
   JOIN purification_payments q ON q.id=x.payment_id JOIN journal_entries z ON z.id=q.journal_id
   WHERE o.series_id=a.series_id AND z.status='posted' AND NOT EXISTS(SELECT 1 FROM purification_releases y WHERE y.allocation_id=x.id);
  IF used+NEW.amount_minor>p.amount_minor::numeric OR paid+NEW.amount_minor>a.due_minor::numeric THEN RAISE EXCEPTION 'Allocation exceeds actual payment capacity or current outstanding'; END IF;
 ELSE
  SELECT * INTO a FROM purification_allocations WHERE id=NEW.allocation_id;
  IF a.owner_id<>NEW.owner_id THEN RAISE EXCEPTION 'Release requires owned original allocation'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purification_obligation_guard BEFORE INSERT ON purification_obligations FOR EACH ROW EXECUTE FUNCTION hwo_purification_guard();
CREATE TRIGGER purification_payment_guard BEFORE INSERT ON purification_payments FOR EACH ROW EXECUTE FUNCTION hwo_purification_guard();
CREATE TRIGGER purification_allocation_guard BEFORE INSERT ON purification_allocations FOR EACH ROW EXECUTE FUNCTION hwo_purification_guard();
CREATE TRIGGER purification_release_guard BEFORE INSERT ON purification_releases FOR EACH ROW EXECUTE FUNCTION hwo_purification_guard();
CREATE TRIGGER retain_purification_obligations BEFORE UPDATE OR DELETE ON purification_obligations FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_purification_payments BEFORE UPDATE OR DELETE ON purification_payments FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_purification_allocations BEFORE UPDATE OR DELETE ON purification_allocations FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_purification_releases BEFORE UPDATE OR DELETE ON purification_releases FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
SQL);
    }

    public function down(): void
    {
        foreach (['purification_obligations', 'purification_payments', 'purification_allocations', 'purification_releases'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retained purification evidence requires a forward migration; downgrade refused before DDL.');
            }
        }
        DB::unprepared('DROP TABLE purification_releases,purification_allocations,purification_payments,purification_obligations; DROP FUNCTION hwo_purification_guard();');
    }
};
