<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE portfolio_modes ADD COLUMN investment_watermark BIGINT NOT NULL DEFAULT 0;
CREATE TABLE investment_events (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 portfolio_account_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 reference CHAR(26) NOT NULL UNIQUE, sort_id BIGINT NOT NULL,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL, request_payload JSONB NOT NULL,
 kind VARCHAR(20) NOT NULL CHECK(kind IN ('buy','sell','dividend','fee','split','ipo_intent','ipo_reserve','ipo_release','ipo_pay','ipo_allot','ipo_refund','ipo_cancel','reversal')),
 effective_date DATE NOT NULL CHECK(effective_date BETWEEN DATE '0001-01-01' AND DATE '9998-12-31'),
 asset_id BIGINT NOT NULL, asset_family CHAR(26) NOT NULL, asset_kind VARCHAR(24) NOT NULL CHECK(asset_kind IN ('equity','unitized_fund')),
 quantity NUMERIC CHECK(quantity>0 AND quantity<1000000000000000000 AND scale(quantity)<=12),
 unit_price NUMERIC CHECK(unit_price>=0 AND unit_price<1000000000000000000 AND scale(unit_price)<=12),
 amount_minor BIGINT NOT NULL CHECK(amount_minor>=0), fee_minor BIGINT NOT NULL DEFAULT 0 CHECK(fee_minor>=0), tax_minor BIGINT NOT NULL DEFAULT 0 CHECK(tax_minor>=0),
 split_numerator NUMERIC CHECK(split_numerator>0 AND split_numerator<1000000000000000000 AND scale(split_numerator)<=12),
 split_denominator NUMERIC CHECK(split_denominator>0 AND split_denominator<1000000000000000000 AND scale(split_denominator)<=12),
 subscription_id BIGINT, reverses_id BIGINT UNIQUE, replaces_id BIGINT UNIQUE,
 action_reference VARCHAR(180) NOT NULL, source VARCHAR(180) NOT NULL, reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(owner_id,idempotency_key), UNIQUE(id,owner_id,portfolio_account_id,currency),
 CHECK((kind='reversal')=(reverses_id IS NOT NULL)),
 CHECK(kind NOT IN ('buy','sell','ipo_allot') OR quantity IS NOT NULL),
 CHECK(kind<>'split' OR (split_numerator IS NOT NULL AND split_denominator IS NOT NULL)),
 CHECK(kind NOT IN ('ipo_reserve','ipo_release','ipo_pay','ipo_allot','ipo_refund','ipo_cancel') OR subscription_id IS NOT NULL),
 FOREIGN KEY(portfolio_account_id,owner_id,currency) REFERENCES portfolio_accounts(id,owner_id,currency) ON DELETE RESTRICT,
 FOREIGN KEY(asset_id,owner_id,currency,asset_kind,asset_family) REFERENCES portfolio_assets(id,owner_id,currency,kind,family_reference) ON DELETE RESTRICT,
 FOREIGN KEY(subscription_id,owner_id,portfolio_account_id,currency) REFERENCES investment_events(id,owner_id,portfolio_account_id,currency) ON DELETE RESTRICT,
 FOREIGN KEY(reverses_id,owner_id,portfolio_account_id,currency) REFERENCES investment_events(id,owner_id,portfolio_account_id,currency) ON DELETE RESTRICT,
 FOREIGN KEY(replaces_id,owner_id,portfolio_account_id,currency) REFERENCES investment_events(id,owner_id,portfolio_account_id,currency) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX investment_business_identity ON investment_events(owner_id,portfolio_account_id,action_reference) WHERE replaces_id IS NULL AND kind<>'reversal';
CREATE INDEX investment_replay ON investment_events(owner_id,portfolio_account_id,effective_date,sort_id,id);
CREATE TABLE investment_event_journals (
 event_id BIGINT PRIMARY KEY, owner_id BIGINT NOT NULL, portfolio_account_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 journal_id BIGINT NOT NULL UNIQUE REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(event_id,owner_id,portfolio_account_id,currency) REFERENCES investment_events(id,owner_id,portfolio_account_id,currency) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION hwo_investment_cash_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e RECORD; original RECORD; original_event RECORD; linked RECORD; j RECORD; a RECORD;
 expected NUMERIC; actual NUMERIC; target BIGINT; clearing NUMERIC; income NUMERIC; expense NUMERIC;
BEGIN
 IF TG_TABLE_NAME='investment_events' THEN target:=NEW.id;
 ELSIF TG_TABLE_NAME='investment_event_journals' THEN target:=NEW.event_id;
 ELSE
  IF NEW.reverses_journal_id IS NOT NULL AND EXISTS(SELECT 1 FROM investment_event_journals WHERE journal_id=NEW.reverses_journal_id) THEN
   SELECT replacement.* INTO linked FROM investment_event_journals replacement JOIN investment_events r ON r.id=replacement.event_id
   JOIN investment_event_journals member ON member.event_id=r.reverses_id WHERE replacement.journal_id=NEW.id AND member.journal_id=NEW.reverses_journal_id;
   IF linked.event_id IS NULL THEN RAISE EXCEPTION 'Investment cash must reverse with its retained investment event'; END IF;
  END IF;
  RETURN NULL;
 END IF;
 SELECT * INTO e FROM investment_events WHERE id=target;
 SELECT * INTO a FROM portfolio_accounts WHERE id=e.portfolio_account_id AND owner_id=e.owner_id;
 SELECT * INTO linked FROM investment_event_journals WHERE event_id=e.id;
 expected:=CASE e.kind WHEN 'buy' THEN -e.amount_minor::numeric-e.fee_minor WHEN 'sell' THEN e.amount_minor::numeric-e.fee_minor
  WHEN 'dividend' THEN e.amount_minor::numeric-e.fee_minor-e.tax_minor WHEN 'fee' THEN -e.amount_minor::numeric
  WHEN 'ipo_pay' THEN -e.amount_minor::numeric WHEN 'ipo_refund' THEN e.amount_minor::numeric ELSE 0 END;
 IF e.kind='reversal' THEN
  SELECT * INTO original_event FROM investment_events WHERE id=e.reverses_id;
  IF original_event.kind='reversal' OR e.effective_date<>original_event.effective_date OR e.sort_id<>original_event.sort_id
   THEN RAISE EXCEPTION 'Investment cancellation retains the original business date and replay order'; END IF;
  SELECT * INTO original FROM investment_event_journals WHERE event_id=e.reverses_id;
  IF original.event_id IS NULL THEN
   IF linked.event_id IS NOT NULL THEN RAISE EXCEPTION 'Cashless reversal cannot invent a journal'; END IF;
   RETURN NULL;
  END IF;
  SELECT -coalesce(sum(amount_minor::numeric),0) INTO expected FROM postings WHERE journal_id=original.journal_id AND account_id=a.account_id AND owner_id=e.owner_id;
 END IF;
 IF e.kind IN ('buy','sell','dividend','fee','ipo_pay','ipo_refund','reversal') THEN
  SELECT * INTO j FROM journal_entries WHERE id=linked.journal_id;
  SELECT coalesce(sum(amount_minor::numeric),0) INTO actual FROM postings WHERE journal_id=linked.journal_id AND account_id=a.account_id AND owner_id=e.owner_id;
  IF j.id IS NULL OR j.owner_id<>e.owner_id OR j.status NOT IN ('posted','reversed') OR j.effective_date<>e.effective_date OR actual<>expected
   THEN RAISE EXCEPTION 'Investment event and exact owned cash effect must commit together'; END IF;
  IF e.kind='reversal' THEN
   IF j.kind<>'reversal' OR j.reverses_journal_id IS DISTINCT FROM original.journal_id
    OR NOT EXISTS(SELECT 1 FROM journal_entries WHERE id=original.journal_id AND status='reversed')
    THEN RAISE EXCEPTION 'Investment cancellation requires its actual original cash member'; END IF;
  ELSE
   clearing:=CASE e.kind WHEN 'buy' THEN e.amount_minor::numeric+e.fee_minor WHEN 'sell' THEN -e.amount_minor::numeric
    WHEN 'ipo_pay' THEN e.amount_minor::numeric WHEN 'ipo_refund' THEN -e.amount_minor::numeric ELSE 0 END;
   income:=CASE WHEN e.kind='dividend' THEN -e.amount_minor::numeric ELSE 0 END;
   expense:=CASE WHEN e.kind='dividend' THEN e.fee_minor::numeric+e.tax_minor WHEN e.kind='sell' THEN e.fee_minor::numeric
    WHEN e.kind='fee' THEN e.amount_minor::numeric ELSE 0 END;
   IF j.kind IS DISTINCT FROM (CASE e.kind WHEN 'dividend' THEN 'income' WHEN 'fee' THEN 'expense' ELSE 'adjustment' END)
    OR j.reverses_journal_id IS NOT NULL OR j.request_payload->'context'->>'investment_event_id' IS DISTINCT FROM e.id::text
    OR EXISTS(SELECT 1 FROM postings p JOIN accounts c ON c.id=p.account_id WHERE p.journal_id=j.id AND
     (p.owner_id<>e.owner_id OR c.owner_id<>e.owner_id OR p.currency_code<>e.currency OR c.currency_code<>e.currency OR (c.id<>a.account_id AND c.system_role IS NULL) OR NOT
      (c.id=a.account_id OR (c.system_role='clearing' AND e.kind IN ('buy','sell','ipo_pay','ipo_refund'))
       OR (c.system_role='income' AND e.kind='dividend') OR (c.system_role='expense' AND e.kind IN ('sell','dividend','fee')))))
    OR (SELECT coalesce(sum(p.amount_minor::numeric),0) FROM postings p JOIN accounts c ON c.id=p.account_id WHERE p.journal_id=j.id AND c.system_role='clearing')<>clearing
    OR (SELECT coalesce(sum(p.amount_minor::numeric),0) FROM postings p JOIN accounts c ON c.id=p.account_id WHERE p.journal_id=j.id AND c.system_role='income')<>income
    OR (SELECT coalesce(sum(p.amount_minor::numeric),0) FROM postings p JOIN accounts c ON c.id=p.account_id WHERE p.journal_id=j.id AND c.system_role='expense')<>expense
    THEN RAISE EXCEPTION 'Investment cash control legs must match exact owned currency roles and amounts'; END IF;
  END IF;
 ELSE
  IF linked.event_id IS NOT NULL THEN RAISE EXCEPTION 'Cashless investment fact cannot post cash'; END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER investment_event_complete AFTER INSERT ON investment_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_investment_cash_guard();
CREATE CONSTRAINT TRIGGER investment_link_complete AFTER INSERT ON investment_event_journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_investment_cash_guard();
CREATE CONSTRAINT TRIGGER investment_cash_reversal AFTER INSERT OR UPDATE ON journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_investment_cash_guard();
CREATE TRIGGER retain_investment_events BEFORE UPDATE OR DELETE ON investment_events FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_investment_links BEFORE UPDATE OR DELETE ON investment_event_journals FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
SQL);
    }

    public function down(): void
    {
        if (DB::table('investment_events')->exists() || DB::table('investment_event_journals')->exists()) {
            throw new RuntimeException('Retained investment events require a forward migration; downgrade refused before DDL.');
        }
        DB::unprepared('DROP TRIGGER investment_cash_reversal ON journal_entries; DROP TABLE investment_event_journals,investment_events; DROP FUNCTION hwo_investment_cash_guard(); ALTER TABLE portfolio_modes DROP COLUMN investment_watermark;');
    }
};
