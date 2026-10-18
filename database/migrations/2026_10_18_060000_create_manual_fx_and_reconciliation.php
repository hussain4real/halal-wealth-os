<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE accounts DROP CONSTRAINT accounts_system_role;
ALTER TABLE accounts ADD CONSTRAINT accounts_system_role CHECK (system_role IS NULL OR (system_role IN ('income','expense','equity','clearing') AND type = 'internal_' || system_role));
CREATE TABLE fx_observations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, UNIQUE(owner_id,idempotency_key),
 series_id CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version > 0), purpose VARCHAR(16) NOT NULL CHECK(purpose IN ('reporting','transaction')),
 base_currency CHAR(3) NOT NULL, quote_currency CHAR(3) NOT NULL, value NUMERIC(30,12) NOT NULL CHECK(value > 0),
 observed_at TIMESTAMPTZ NOT NULL, effective_at TIMESTAMPTZ NOT NULL, source VARCHAR(180) NOT NULL,
 supersedes_id BIGINT UNIQUE, correction_reason VARCHAR(180), created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,series_id,version), FOREIGN KEY(supersedes_id,owner_id) REFERENCES fx_observations(id,owner_id) ON DELETE RESTRICT,
 CHECK(base_currency IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD') AND quote_currency IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD') AND base_currency <> quote_currency)
);
CREATE INDEX fx_reporting_selection ON fx_observations(owner_id,purpose,base_currency,quote_currency,effective_at,observed_at,id);
CREATE TABLE fx_operations (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, reference CHAR(26) NOT NULL UNIQUE,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, request_payload JSONB NOT NULL,
 source_account_id BIGINT NOT NULL, destination_account_id BIGINT NOT NULL, source_currency CHAR(3) NOT NULL, destination_currency CHAR(3) NOT NULL,
 source_minor BIGINT NOT NULL CHECK(source_minor > 0), destination_minor BIGINT NOT NULL CHECK(destination_minor > 0),
 fee_minor BIGINT NOT NULL CHECK(fee_minor >= 0), fee_side VARCHAR(16) NOT NULL CHECK(fee_side IN ('source','destination')),
 actual_rate_id BIGINT NOT NULL, effective_date DATE NOT NULL, description VARCHAR(180) NOT NULL,
 replaces_id BIGINT UNIQUE, status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','posted','reversed')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(id,owner_id), UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(source_account_id,owner_id,source_currency) REFERENCES accounts(id,owner_id,currency_code) ON DELETE RESTRICT,
 FOREIGN KEY(destination_account_id,owner_id,destination_currency) REFERENCES accounts(id,owner_id,currency_code) ON DELETE RESTRICT,
 FOREIGN KEY(actual_rate_id,owner_id) REFERENCES fx_observations(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(replaces_id,owner_id) REFERENCES fx_operations(id,owner_id) ON DELETE RESTRICT,
 CHECK(source_currency <> destination_currency AND source_account_id <> destination_account_id),
 CHECK(fee_side <> 'destination' OR fee_minor <= destination_minor)
);
CREATE TABLE fx_operation_journals (
 operation_id BIGINT NOT NULL, owner_id BIGINT NOT NULL, journal_id BIGINT NOT NULL UNIQUE,
 role VARCHAR(24) NOT NULL CHECK(role IN ('source','destination','fee','source_reversal','destination_reversal','fee_reversal')),
 PRIMARY KEY(operation_id,role), FOREIGN KEY(operation_id,owner_id) REFERENCES fx_operations(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_id,owner_id) REFERENCES journal_entries(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE fx_reversals (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, operation_id BIGINT NOT NULL UNIQUE,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(operation_id,owner_id) REFERENCES fx_operations(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE valuation_reports (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, reference CHAR(26) NOT NULL UNIQUE,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, base_currency CHAR(3) NOT NULL,
 cutoff TIMESTAMPTZ NOT NULL, evidence JSONB NOT NULL, evidence_hash CHAR(64) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(owner_id,idempotency_key)
);
CREATE TABLE reconciliation_sessions (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, reference CHAR(26) NOT NULL UNIQUE,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, account_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 statement_minor BIGINT NOT NULL, cutoff TIMESTAMPTZ NOT NULL, evidence JSONB NOT NULL, evidence_hash CHAR(64) NOT NULL,
 ledger_minor BIGINT, difference NUMERIC(21,0), status VARCHAR(16) NOT NULL CHECK(status IN ('matched','difference','unavailable')),
 supersedes_id BIGINT, adjustment_journal_id BIGINT, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,idempotency_key),
 FOREIGN KEY(account_id,owner_id,currency) REFERENCES accounts(id,owner_id,currency_code) ON DELETE RESTRICT,
 FOREIGN KEY(supersedes_id,owner_id) REFERENCES reconciliation_sessions(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(adjustment_journal_id,owner_id) REFERENCES journal_entries(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE reconciliation_adjustments (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 original_session_id BIGINT NOT NULL UNIQUE, result_session_id BIGINT NOT NULL UNIQUE, journal_id BIGINT NOT NULL UNIQUE,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, reason VARCHAR(180) NOT NULL,
 offset_role VARCHAR(16) NOT NULL CHECK(offset_role = 'equity'), created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(owner_id,idempotency_key), FOREIGN KEY(original_session_id,owner_id) REFERENCES reconciliation_sessions(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(result_session_id,owner_id) REFERENCES reconciliation_sessions(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_id,owner_id) REFERENCES journal_entries(id,owner_id) ON DELETE RESTRICT
);
CREATE TRIGGER retain_fx_observations BEFORE UPDATE OR DELETE ON fx_observations FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_fx_members BEFORE UPDATE OR DELETE ON fx_operation_journals FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_fx_reversals BEFORE UPDATE OR DELETE ON fx_reversals FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_valuations BEFORE UPDATE OR DELETE ON valuation_reports FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_reconciliations BEFORE UPDATE OR DELETE ON reconciliation_sessions FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_reconciliation_adjustments BEFORE UPDATE OR DELETE ON reconciliation_adjustments FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE OR REPLACE FUNCTION hwo_fx_write_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR (to_jsonb(NEW) - 'status') IS DISTINCT FROM (to_jsonb(OLD) - 'status')
 OR NOT ((OLD.status = 'pending' AND NEW.status = 'posted') OR (OLD.status = 'posted' AND NEW.status = 'reversed'))
 THEN RAISE EXCEPTION 'FX history is immutable except complete posting or reversal'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER retain_fx_operations BEFORE UPDATE OR DELETE ON fx_operations FOR EACH ROW EXECUTE FUNCTION hwo_fx_write_guard();
CREATE OR REPLACE FUNCTION hwo_validate_fx_group(group_id BIGINT) RETURNS void LANGUAGE plpgsql AS $$
DECLARE g RECORD; m RECORD; cash_id BIGINT; expected NUMERIC; expected_currency TEXT; counter_role TEXT; required INTEGER;
BEGIN
 SELECT * INTO g FROM fx_operations WHERE id = group_id;
 IF NOT FOUND THEN RETURN; END IF;
 required := 2 + CASE WHEN g.fee_minor > 0 THEN 1 ELSE 0 END;
 IF g.status = 'pending' OR (SELECT count(*) FROM fx_operation_journals WHERE operation_id = g.id AND role IN ('source','destination','fee')) <> required
 OR NOT EXISTS (SELECT 1 FROM fx_operation_journals WHERE operation_id = g.id AND role = 'source')
 OR NOT EXISTS (SELECT 1 FROM fx_operation_journals WHERE operation_id = g.id AND role = 'destination')
 OR ((g.fee_minor > 0) <> EXISTS (SELECT 1 FROM fx_operation_journals WHERE operation_id = g.id AND role = 'fee'))
 THEN RAISE EXCEPTION 'FX operation must commit all currency and fee journals together'; END IF;
 IF NOT EXISTS (SELECT 1 FROM fx_observations q WHERE q.id = g.actual_rate_id AND q.owner_id = g.owner_id AND q.purpose = 'transaction' AND q.base_currency = g.source_currency AND q.quote_currency = g.destination_currency)
 THEN RAISE EXCEPTION 'FX transaction rate must match owned pair and purpose'; END IF;
 FOR m IN SELECT l.role,j.* FROM fx_operation_journals l JOIN journal_entries j ON j.id = l.journal_id WHERE l.operation_id = g.id AND l.role IN ('source','destination','fee') LOOP
  IF (g.status = 'posted' AND m.status <> 'posted') OR (g.status = 'reversed' AND m.status <> 'reversed') THEN RAISE EXCEPTION 'FX constituents cannot be reversed separately'; END IF;
  IF m.role = 'source' THEN cash_id := g.source_account_id; expected := -g.source_minor::numeric; expected_currency := g.source_currency; counter_role := 'clearing';
  ELSIF m.role = 'destination' THEN cash_id := g.destination_account_id; expected := g.destination_minor; expected_currency := g.destination_currency; counter_role := 'clearing';
  ELSE cash_id := CASE WHEN g.fee_side = 'source' THEN g.source_account_id ELSE g.destination_account_id END; expected := -g.fee_minor::numeric; expected_currency := CASE WHEN g.fee_side = 'source' THEN g.source_currency ELSE g.destination_currency END; counter_role := 'expense'; END IF;
  IF m.kind <> (CASE WHEN m.role = 'fee' THEN 'expense' ELSE 'adjustment' END) OR m.effective_date <> g.effective_date
  OR NOT EXISTS (SELECT 1 FROM accounts WHERE id = cash_id AND owner_id = g.owner_id AND system_role IS NULL AND type IN ('bank','cash','investment','liability'))
  OR (SELECT coalesce(sum(amount_minor::numeric),0) FROM postings WHERE journal_id = m.id AND account_id = cash_id) <> expected
  OR EXISTS (SELECT 1 FROM postings p JOIN accounts a ON a.id = p.account_id WHERE p.journal_id = m.id AND (p.currency_code <> expected_currency OR p.owner_id <> g.owner_id OR (p.account_id <> cash_id AND a.system_role IS DISTINCT FROM counter_role)))
  THEN RAISE EXCEPTION 'FX journal movements must match immutable operation evidence'; END IF;
  IF g.status = 'reversed' AND NOT EXISTS (SELECT 1 FROM fx_operation_journals l JOIN journal_entries j ON j.id = l.journal_id WHERE l.operation_id = g.id AND l.role = m.role || '_reversal' AND j.reverses_journal_id = m.id AND j.status = 'posted')
  THEN RAISE EXCEPTION 'FX reversal must retain every reversing journal'; END IF;
 END LOOP;
 IF g.status = 'posted' AND (EXISTS (SELECT 1 FROM fx_reversals WHERE operation_id = g.id) OR EXISTS (SELECT 1 FROM fx_operation_journals WHERE operation_id = g.id AND role LIKE '%_reversal'))
 OR g.status = 'reversed' AND ((SELECT count(*) FROM fx_operation_journals WHERE operation_id = g.id AND role LIKE '%_reversal') <> required OR NOT EXISTS (SELECT 1 FROM fx_reversals WHERE operation_id = g.id))
 THEN RAISE EXCEPTION 'FX reversal identity and all journals must commit together'; END IF;
END $$;
CREATE OR REPLACE FUNCTION hwo_check_fx_group() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE group_id BIGINT;
BEGIN
 IF TG_TABLE_NAME = 'fx_operations' THEN PERFORM hwo_validate_fx_group(NEW.id);
 ELSIF TG_TABLE_NAME IN ('fx_operation_journals','fx_reversals') THEN PERFORM hwo_validate_fx_group(NEW.operation_id);
 ELSE FOR group_id IN SELECT operation_id FROM fx_operation_journals WHERE journal_id = NEW.id LOOP PERFORM hwo_validate_fx_group(group_id); END LOOP;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER complete_fx_operation AFTER INSERT OR UPDATE ON fx_operations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_check_fx_group();
CREATE CONSTRAINT TRIGGER complete_fx_members AFTER INSERT ON fx_operation_journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_check_fx_group();
CREATE CONSTRAINT TRIGGER complete_fx_reversal AFTER INSERT ON fx_reversals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_check_fx_group();
CREATE CONSTRAINT TRIGGER retain_fx_group_on_journal AFTER UPDATE ON journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_check_fx_group();
SQL);
    }

    public function down(): void
    {
        // Retained financial evidence must survive an attempted deployment rollback.
        // An empty installation can roll back; populated M1-P3 is forward-only.
        if (DB::table('accounts')->where('system_role', 'clearing')->exists()) {
            throw new RuntimeException('M1-P3 contains retained clearing accounts; use a forward migration instead of rollback.');
        }
        foreach (['fx_observations', 'fx_operations', 'valuation_reports', 'reconciliation_sessions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('M1-P3 contains retained financial evidence; use a forward migration instead of rollback.');
            }
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS retain_fx_group_on_journal ON journal_entries;
DROP TABLE IF EXISTS reconciliation_adjustments, reconciliation_sessions, valuation_reports, fx_reversals, fx_operation_journals, fx_operations, fx_observations;
DROP FUNCTION IF EXISTS hwo_check_fx_group();
DROP FUNCTION IF EXISTS hwo_validate_fx_group(BIGINT);
DROP FUNCTION IF EXISTS hwo_fx_write_guard();
ALTER TABLE accounts DROP CONSTRAINT accounts_system_role;
ALTER TABLE accounts ADD CONSTRAINT accounts_system_role CHECK (system_role IS NULL OR (system_role IN ('income','expense','equity') AND type = 'internal_' || system_role));
SQL);
    }
};
