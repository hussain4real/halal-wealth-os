<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE goal_plans (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, family_reference CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version>0),
 supersedes_id BIGINT UNIQUE, account_id BIGINT NOT NULL, name VARCHAR(180) NOT NULL,
 currency CHAR(3) NOT NULL CHECK(currency IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD')),
 mode VARCHAR(12) NOT NULL CHECK(mode IN ('dedicated','reserved')),
 target_minor BIGINT NOT NULL CHECK(target_minor>0), opening_minor BIGINT NOT NULL CHECK(opening_minor>=0),
 opening_journal_cutoff BIGINT NOT NULL CHECK(opening_journal_cutoff>=0),
 policy VARCHAR(16) NOT NULL CHECK(policy IN ('fixed_monthly','variable')),
 contribution_minor BIGINT NOT NULL CHECK(contribution_minor>=0), first_deposit DATE, target_date DATE,
 CHECK(first_deposit IS NULL OR first_deposit BETWEEN DATE '0001-01-01' AND DATE '9998-12-31'),
 CHECK(target_date IS NULL OR target_date BETWEEN DATE '0001-01-01' AND DATE '9998-12-31'),
 standing_order BOOLEAN NOT NULL, reminders BOOLEAN NOT NULL DEFAULT FALSE CHECK(NOT reminders),
 closed BOOLEAN NOT NULL, reason VARCHAR(180), created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,family_reference,version),
 FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES goal_plans(id,owner_id) ON DELETE RESTRICT
);
CREATE INDEX goal_plans_owner_family ON goal_plans(owner_id,family_reference,id DESC);
CREATE INDEX goal_plans_account ON goal_plans(owner_id,account_id);
CREATE TABLE goal_reservations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, goal_id BIGINT NOT NULL, account_id BIGINT NOT NULL,
 amount_minor BIGINT NOT NULL CHECK(amount_minor>0), reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id),
 FOREIGN KEY(goal_id,owner_id) REFERENCES goal_plans(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE RESTRICT
);
CREATE INDEX goal_reservations_account ON goal_reservations(owner_id,account_id,id);
CREATE INDEX goal_reservations_goal ON goal_reservations(owner_id,goal_id,id DESC);
CREATE TABLE goal_releases (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, reservation_id BIGINT NOT NULL UNIQUE, reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id),
 FOREIGN KEY(reservation_id,owner_id) REFERENCES goal_reservations(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE goal_funding_links (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, goal_id BIGINT NOT NULL, posting_id BIGINT NOT NULL,
 reason VARCHAR(180) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(goal_id,posting_id),
 FOREIGN KEY(goal_id,owner_id) REFERENCES goal_plans(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(posting_id) REFERENCES postings(id) ON DELETE RESTRICT
);
CREATE INDEX goal_links_history ON goal_funding_links(owner_id,goal_id,id DESC);
CREATE TABLE goal_requests (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 operation VARCHAR(12) NOT NULL CHECK(operation IN ('plan','reserve','release','link')),
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 result_id BIGINT NOT NULL, result_reference CHAR(26) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(owner_id,operation,idempotency_key)
);
CREATE OR REPLACE FUNCTION hwo_goal_lock() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hwo:posting:owner:'||NEW.owner_id::text,0));
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION hwo_goal_opening_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a RECORD; cash NUMERIC; cutoff BIGINT;
BEGIN
 IF NEW.supersedes_id IS NOT NULL THEN RETURN NEW; END IF;
 SELECT * INTO STRICT a FROM accounts WHERE id=NEW.account_id AND owner_id=NEW.owner_id FOR UPDATE;
 SELECT coalesce(sum(p.amount_minor::numeric),0) INTO cash FROM postings p JOIN journal_entries j ON j.id=p.journal_id
  WHERE p.owner_id=NEW.owner_id AND p.account_id=a.id AND j.status IN ('posted','reversed');
 SELECT coalesce(max(id),0) INTO cutoff FROM journal_entries WHERE owner_id=NEW.owner_id;
 IF a.type NOT IN ('bank','cash') OR a.currency_code<>NEW.currency OR a.archived_at IS NOT NULL OR cash<0 OR cash>9223372036854775807
  OR NEW.opening_journal_cutoff<>cutoff OR (NEW.mode='dedicated' AND NEW.opening_minor<>cash)
  OR EXISTS(SELECT 1 FROM opening_balance_intents i WHERE i.account_id=a.id AND NOT EXISTS(SELECT 1 FROM opening_balance_postings op WHERE op.opening_intent_id=i.id))
 THEN RAISE EXCEPTION 'Opening snapshot requires current owned known liquid cash'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION hwo_goal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a RECORD; g RECORD; previous RECORD; cash NUMERIC; claims NUMERIC;
BEGIN
 IF TG_TABLE_NAME='goal_plans' THEN
  SELECT * INTO STRICT a FROM accounts WHERE id=NEW.account_id AND owner_id=NEW.owner_id FOR UPDATE;
  IF a.type NOT IN ('bank','cash') OR a.currency_code<>NEW.currency THEN RAISE EXCEPTION 'Goal requires owned native liquid account'; END IF;
  IF NEW.supersedes_id IS NULL THEN
   IF NEW.version<>1 OR NEW.family_reference<>NEW.reference OR NEW.closed THEN RAISE EXCEPTION 'Invalid active goal root'; END IF;
  ELSE
   SELECT * INTO STRICT previous FROM goal_plans WHERE id=NEW.supersedes_id AND owner_id=NEW.owner_id;
   IF previous.closed OR NEW.version<>previous.version+1 OR NEW.family_reference<>previous.family_reference OR NEW.mode<>previous.mode OR NEW.currency<>previous.currency OR NEW.account_id<>previous.account_id
    OR NEW.opening_minor<>previous.opening_minor OR NEW.opening_journal_cutoff<>previous.opening_journal_cutoff OR length(trim(coalesce(NEW.reason,'')))=0
   THEN RAISE EXCEPTION 'Goal version retains backing identity and history'; END IF;
  END IF;
  IF NEW.supersedes_id IS NULL AND NEW.mode='reserved' AND NEW.opening_minor>0 AND NOT EXISTS(SELECT 1 FROM goal_reservations r WHERE r.goal_id=NEW.id AND r.owner_id=NEW.owner_id AND r.amount_minor=NEW.opening_minor AND r.reason='Opening reservation from existing cash') THEN RAISE EXCEPTION 'Opening reservation requires actual capacity grant'; END IF;
  IF NEW.closed AND EXISTS(SELECT 1 FROM goal_reservations r JOIN goal_plans root ON root.id=r.goal_id WHERE root.family_reference=NEW.family_reference
   AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id)) THEN RAISE EXCEPTION 'Release reservations before closing goal'; END IF;
  IF NOT NEW.closed AND NOT EXISTS(SELECT 1 FROM goal_plans n WHERE n.supersedes_id=NEW.id) AND EXISTS(SELECT 1 FROM goal_plans other WHERE other.owner_id=NEW.owner_id AND other.account_id=NEW.account_id AND other.family_reference<>NEW.family_reference
   AND NOT other.closed AND NOT EXISTS(SELECT 1 FROM goal_plans n WHERE n.supersedes_id=other.id)
   AND (NEW.mode='dedicated' OR other.mode='dedicated')) THEN RAISE EXCEPTION 'Dedicated account is exclusive across goal modes'; END IF;
 ELSIF TG_TABLE_NAME='goal_reservations' THEN
  SELECT * INTO STRICT g FROM goal_plans WHERE id=NEW.goal_id AND owner_id=NEW.owner_id;
  SELECT * INTO STRICT a FROM accounts WHERE id=NEW.account_id AND owner_id=NEW.owner_id FOR UPDATE;
  IF g.supersedes_id IS NOT NULL OR g.mode<>'reserved' OR g.account_id<>NEW.account_id THEN RAISE EXCEPTION 'Reservation backing identity mismatch'; END IF;
  IF EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=NEW.id) THEN RETURN NEW; END IF;
  IF a.archived_at IS NOT NULL OR (SELECT closed FROM goal_plans WHERE family_reference=g.family_reference AND owner_id=NEW.owner_id ORDER BY version DESC LIMIT 1)
  THEN RAISE EXCEPTION 'Reservation requires active reserved goal and account'; END IF;
  IF EXISTS(SELECT 1 FROM opening_balance_intents i WHERE i.account_id=a.id AND NOT EXISTS(SELECT 1 FROM opening_balance_postings op WHERE op.opening_intent_id=i.id))
  THEN RAISE EXCEPTION 'Pending opening cannot back a reservation'; END IF;
  SELECT coalesce(sum(p.amount_minor::numeric),0) INTO cash FROM postings p JOIN journal_entries j ON j.id=p.journal_id
   WHERE p.owner_id=NEW.owner_id AND p.account_id=a.id AND j.status IN ('posted','reversed');
  SELECT coalesce(sum(r.amount_minor::numeric),0) INTO claims FROM goal_reservations r WHERE r.owner_id=NEW.owner_id AND r.account_id=a.id
   AND NOT EXISTS(SELECT 1 FROM goal_releases rel WHERE rel.reservation_id=r.id);
  IF cash<0 OR cash>9223372036854775807 OR claims>cash THEN RAISE EXCEPTION 'Reservation capacity exceeded or unavailable'; END IF;
 ELSE
  SELECT * INTO STRICT g FROM goal_plans WHERE id=NEW.goal_id AND owner_id=NEW.owner_id;
  IF g.supersedes_id IS NOT NULL OR NOT EXISTS(SELECT 1 FROM postings p JOIN journal_entries j ON j.id=p.journal_id WHERE p.id=NEW.posting_id AND p.owner_id=NEW.owner_id
    AND p.account_id=g.account_id AND p.currency_code=g.currency AND j.owner_id=NEW.owner_id AND j.status IN ('posted','reversed'))
  THEN RAISE EXCEPTION 'Funding link requires owned native goal account posting'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['plans', 'reservations', 'releases', 'funding_links', 'requests'] as $table) {
            DB::unprepared("CREATE TRIGGER lock_goal_$table BEFORE INSERT ON goal_$table FOR EACH ROW EXECUTE FUNCTION hwo_goal_lock(); CREATE TRIGGER retain_goal_$table BEFORE UPDATE OR DELETE ON goal_$table FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
        DB::unprepared('CREATE TRIGGER opening_goal_plan BEFORE INSERT ON goal_plans FOR EACH ROW EXECUTE FUNCTION hwo_goal_opening_guard()');
        foreach (['plans', 'reservations', 'funding_links'] as $table) {
            DB::unprepared("CREATE CONSTRAINT TRIGGER guard_goal_$table AFTER INSERT ON goal_$table DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_goal_guard()");
        }
    }

    public function down(): void
    {
        foreach (['plans', 'reservations', 'releases', 'funding_links', 'requests'] as $table) {
            if (DB::table("goal_$table")->exists()) {
                throw new RuntimeException('Retained goal history requires a forward migration; downgrade refused before DDL.');
            }
        }
        DB::unprepared('DROP TABLE goal_requests,goal_funding_links,goal_releases,goal_reservations,goal_plans; DROP FUNCTION hwo_goal_guard(); DROP FUNCTION hwo_goal_lock(); DROP FUNCTION hwo_goal_opening_guard();');
    }
};
