<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE planning_categories (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, family_reference CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version > 0),
 name VARCHAR(180) NOT NULL, direction VARCHAR(8) NOT NULL CHECK(direction IN ('income','expense')),
 normal_budget BOOLEAN NOT NULL, emergency_essential BOOLEAN NOT NULL,
 supersedes_id BIGINT UNIQUE, reason VARCHAR(180), created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,family_reference,version),
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES planning_categories(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE planning_schedules (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, family_reference CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version > 0),
 category_id BIGINT NOT NULL, name VARCHAR(180) NOT NULL,
 currency CHAR(3) NOT NULL CHECK(currency IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD')),
 expected_minor BIGINT NOT NULL CHECK(expected_minor > 0),
 cadence VARCHAR(8) NOT NULL CHECK(cadence IN ('once','monthly','term','annual')),
 interval_months INTEGER NOT NULL CHECK(interval_months BETWEEN 0 AND 24),
 first_due_date DATE NOT NULL, provision_start_date DATE NOT NULL, end_date DATE, cutover_date DATE NOT NULL,
 salary BOOLEAN NOT NULL, standing_order BOOLEAN NOT NULL,
 supersedes_id BIGINT UNIQUE, reason VARCHAR(180), created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,family_reference,version),
 FOREIGN KEY(category_id,owner_id) REFERENCES planning_categories(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES planning_schedules(id,owner_id) ON DELETE RESTRICT,
 CHECK(end_date IS NULL OR end_date >= first_due_date), CHECK(first_due_date >= provision_start_date AND provision_start_date >= cutover_date),
 CHECK((cadence='once' AND interval_months=0) OR (cadence='monthly' AND interval_months=1)
 OR (cadence='annual' AND interval_months=12) OR (cadence='term' AND interval_months BETWEEN 2 AND 24)),
 CHECK(NOT salary OR cadence='monthly')
);
CREATE TABLE planning_occurrences (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, schedule_id BIGINT NOT NULL, due_date DATE NOT NULL,
 category_id BIGINT NOT NULL, currency CHAR(3) NOT NULL, expected_minor BIGINT NOT NULL CHECK(expected_minor > 0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id), UNIQUE(schedule_id,due_date),
 FOREIGN KEY(schedule_id,owner_id) REFERENCES planning_schedules(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(category_id,owner_id) REFERENCES planning_categories(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE planning_attributions (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, journal_id BIGINT NOT NULL, category_id BIGINT NOT NULL,
 supersedes_id BIGINT UNIQUE, reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id),
 FOREIGN KEY(journal_id,owner_id) REFERENCES journal_entries(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(category_id,owner_id) REFERENCES planning_categories(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES planning_attributions(id,owner_id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX planning_first_attribution ON planning_attributions(journal_id) WHERE supersedes_id IS NULL;
CREATE TABLE planning_allocations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, occurrence_id BIGINT NOT NULL, journal_id BIGINT NOT NULL,
 amount_minor BIGINT NOT NULL CHECK(amount_minor > 0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id),
 FOREIGN KEY(occurrence_id,owner_id) REFERENCES planning_occurrences(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_id,owner_id) REFERENCES journal_entries(id,owner_id) ON DELETE RESTRICT
);
CREATE INDEX planning_allocations_journal ON planning_allocations(owner_id,journal_id);
CREATE TABLE planning_releases (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, allocation_id BIGINT NOT NULL UNIQUE, reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id),
 FOREIGN KEY(allocation_id,owner_id) REFERENCES planning_allocations(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE planning_requests (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 operation VARCHAR(12) NOT NULL CHECK(operation IN ('category','schedule','attribute','allocate','release')),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 result_id BIGINT NOT NULL, result_reference CHAR(26) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(owner_id,operation,idempotency_key)
);
CREATE OR REPLACE FUNCTION hwo_planning_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous RECORD; c RECORD; s RECORD; months INTEGER; intended DATE;
BEGIN
 IF TG_TABLE_NAME='planning_categories' THEN
  IF NEW.supersedes_id IS NULL THEN
   IF NEW.version<>1 OR NEW.family_reference<>NEW.reference THEN RAISE EXCEPTION 'Invalid category root'; END IF;
  ELSE
   SELECT * INTO STRICT previous FROM planning_categories WHERE id=NEW.supersedes_id AND owner_id=NEW.owner_id;
   IF NEW.version<>previous.version+1 OR NEW.family_reference<>previous.family_reference OR NEW.direction<>previous.direction OR length(trim(coalesce(NEW.reason,'')))=0
   THEN RAISE EXCEPTION 'Category revisions retain family/direction and require a reason'; END IF;
  END IF;
 ELSIF TG_TABLE_NAME='planning_schedules' THEN
  SELECT * INTO STRICT c FROM planning_categories WHERE id=NEW.category_id AND owner_id=NEW.owner_id;
  IF NEW.salary AND c.direction<>'income' THEN RAISE EXCEPTION 'Salary requires an income category'; END IF;
  IF NEW.supersedes_id IS NULL THEN
   IF NEW.version<>1 OR NEW.family_reference<>NEW.reference THEN RAISE EXCEPTION 'Invalid schedule root'; END IF;
  ELSE
   SELECT * INTO STRICT previous FROM planning_schedules WHERE id=NEW.supersedes_id AND owner_id=NEW.owner_id;
   IF NEW.version<>previous.version+1 OR NEW.family_reference<>previous.family_reference OR (previous.supersedes_id IS NOT NULL AND NEW.cutover_date<previous.cutover_date) OR length(trim(coalesce(NEW.reason,'')))=0
   THEN RAISE EXCEPTION 'Invalid schedule revision'; END IF;
   IF EXISTS (SELECT 1 FROM planning_occurrences o JOIN planning_allocations a ON a.occurrence_id=o.id JOIN journal_entries j ON j.id=a.journal_id
    WHERE o.schedule_id=previous.id AND o.due_date>=NEW.cutover_date AND j.status='posted'
    AND NOT EXISTS(SELECT 1 FROM planning_releases r WHERE r.allocation_id=a.id))
   THEN RAISE EXCEPTION 'Release effective future allocations before revising'; END IF;
  END IF;
 ELSE
  SELECT * INTO STRICT s FROM planning_schedules WHERE id=NEW.schedule_id AND owner_id=NEW.owner_id;
  months := (extract(year FROM NEW.due_date)::integer-extract(year FROM s.first_due_date)::integer)*12
          + extract(month FROM NEW.due_date)::integer-extract(month FROM s.first_due_date)::integer;
  intended := (s.first_due_date + make_interval(months=>months))::date;
  IF NEW.category_id<>s.category_id OR NEW.currency<>s.currency OR NEW.expected_minor<>s.expected_minor
   OR NEW.due_date<s.first_due_date OR (s.end_date IS NOT NULL AND NEW.due_date>s.end_date)
   OR NEW.due_date<>intended OR (s.interval_months=0 AND months<>0) OR (s.interval_months>0 AND months%nullif(s.interval_months,0)<>0)
   OR EXISTS(SELECT 1 FROM planning_schedules next WHERE next.supersedes_id=s.id AND next.cutover_date<=NEW.due_date)
  THEN RAISE EXCEPTION 'Occurrence must match its eligible immutable rule version'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER planning_category_version BEFORE INSERT ON planning_categories FOR EACH ROW EXECUTE FUNCTION hwo_planning_version_guard();
CREATE TRIGGER planning_schedule_version BEFORE INSERT ON planning_schedules FOR EACH ROW EXECUTE FUNCTION hwo_planning_version_guard();
CREATE TRIGGER planning_occurrence_version BEFORE INSERT ON planning_occurrences FOR EACH ROW EXECUTE FUNCTION hwo_planning_version_guard();
CREATE OR REPLACE FUNCTION hwo_planning_attribution_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE j RECORD; c RECORD; previous RECORD;
BEGIN
 SELECT * INTO STRICT j FROM journal_entries WHERE id=NEW.journal_id AND owner_id=NEW.owner_id;
 SELECT * INTO STRICT c FROM planning_categories WHERE id=NEW.category_id AND owner_id=NEW.owner_id;
 IF j.status NOT IN ('posted','reversed') OR j.kind NOT IN ('income','expense') OR c.direction<>j.kind
 THEN RAISE EXCEPTION 'Only owned economic activity can be classified'; END IF;
 IF NEW.supersedes_id IS NOT NULL THEN
  SELECT * INTO STRICT previous FROM planning_attributions WHERE id=NEW.supersedes_id AND owner_id=NEW.owner_id;
  IF previous.journal_id<>NEW.journal_id THEN RAISE EXCEPTION 'Attribution revision retains actual identity'; END IF;
 END IF;
 IF EXISTS(SELECT 1 FROM planning_allocations a JOIN planning_occurrences o ON o.id=a.occurrence_id
  JOIN planning_categories oc ON oc.id=o.category_id WHERE a.journal_id=j.id AND j.status='posted'
  AND NOT EXISTS(SELECT 1 FROM planning_releases r WHERE r.allocation_id=a.id) AND oc.family_reference<>c.family_reference)
 THEN RAISE EXCEPTION 'Release effective allocations before changing category family'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER planning_attribution_version BEFORE INSERT ON planning_attributions FOR EACH ROW EXECUTE FUNCTION hwo_planning_attribution_guard();
CREATE OR REPLACE FUNCTION hwo_planning_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o RECORD; j RECORD; c RECORD; actual_category RECORD; actual_minor NUMERIC; currencies INTEGER;
BEGIN
 IF EXISTS(SELECT 1 FROM planning_releases WHERE allocation_id=NEW.id) THEN RETURN NEW; END IF;
 SELECT * INTO STRICT o FROM planning_occurrences WHERE id=NEW.occurrence_id AND owner_id=NEW.owner_id;
 SELECT * INTO STRICT j FROM journal_entries WHERE id=NEW.journal_id AND owner_id=NEW.owner_id;
 SELECT * INTO STRICT c FROM planning_categories WHERE id=o.category_id AND owner_id=NEW.owner_id;
 SELECT pc.* INTO actual_category FROM planning_attributions pa JOIN planning_categories pc ON pc.id=pa.category_id
  WHERE pa.journal_id=j.id AND pa.owner_id=NEW.owner_id AND NOT EXISTS(SELECT 1 FROM planning_attributions next WHERE next.supersedes_id=pa.id);
 SELECT count(DISTINCT p.currency_code), sum(p.amount_minor::numeric)*CASE WHEN j.kind='income' THEN -1 ELSE 1 END
 INTO currencies,actual_minor FROM postings p JOIN accounts ac ON ac.id=p.account_id AND ac.owner_id=p.owner_id
 WHERE p.journal_id=j.id AND p.owner_id=NEW.owner_id AND ac.system_role=j.kind;
 IF j.status<>'posted' OR j.kind NOT IN ('income','expense') OR j.kind<>c.direction OR actual_category.id IS NULL
  OR actual_category.family_reference<>c.family_reference OR currencies<>1 OR actual_minor IS NULL OR actual_minor<=0
  OR NOT EXISTS(SELECT 1 FROM postings p JOIN accounts ac ON ac.id=p.account_id WHERE p.journal_id=j.id AND ac.system_role=j.kind AND p.currency_code=o.currency)
  OR EXISTS(SELECT 1 FROM planning_schedules next WHERE next.supersedes_id=o.schedule_id AND next.cutover_date<=o.due_date)
 THEN RAISE EXCEPTION 'Allocation requires eligible same-currency/category posted actual and current occurrence'; END IF;
 IF (SELECT coalesce(sum(a.amount_minor::numeric),0) FROM planning_allocations a JOIN journal_entries aj ON aj.id=a.journal_id
     WHERE a.occurrence_id=o.id AND aj.status='posted' AND NOT EXISTS(SELECT 1 FROM planning_releases r WHERE r.allocation_id=a.id))>o.expected_minor
 OR (SELECT coalesce(sum(a.amount_minor::numeric),0) FROM planning_allocations a WHERE a.journal_id=j.id
     AND NOT EXISTS(SELECT 1 FROM planning_releases r WHERE r.allocation_id=a.id))>actual_minor
 OR (SELECT count(*) FROM planning_allocations a WHERE a.journal_id=j.id AND a.occurrence_id=o.id
     AND NOT EXISTS(SELECT 1 FROM planning_releases r WHERE r.allocation_id=a.id))>1
 THEN RAISE EXCEPTION 'Allocation capacity or active pair exceeded'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER planning_allocation_capacity AFTER INSERT ON planning_allocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_planning_allocation_guard();
SQL);
        foreach (['categories', 'schedules', 'occurrences', 'attributions', 'allocations', 'releases', 'requests'] as $table) {
            DB::unprepared("CREATE TRIGGER retain_planning_$table BEFORE UPDATE OR DELETE ON planning_$table FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
    }

    public function down(): void
    {
        foreach (['categories', 'schedules', 'occurrences', 'attributions', 'allocations', 'releases', 'requests'] as $table) {
            if (DB::table("planning_$table")->exists()) {
                throw new RuntimeException('Retained planning history requires a forward migration; downgrade refused before DDL.');
            }
        }
        DB::unprepared('DROP TABLE planning_requests,planning_releases,planning_allocations,planning_attributions,planning_occurrences,planning_schedules,planning_categories; DROP FUNCTION hwo_planning_allocation_guard(); DROP FUNCTION hwo_planning_attribution_guard(); DROP FUNCTION hwo_planning_version_guard();');
    }
};
