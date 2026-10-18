<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE accounts ADD CONSTRAINT portfolio_account_owner_currency UNIQUE(id,owner_id,currency_code);
CREATE TABLE portfolio_assets (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, family_reference CHAR(26) NOT NULL,
 version INTEGER NOT NULL CHECK(version>0), supersedes_id BIGINT UNIQUE,
 name VARCHAR(180) NOT NULL, kind VARCHAR(24) NOT NULL CHECK(kind IN ('equity','unitized_fund','manual_investment')),
 currency CHAR(3) NOT NULL CHECK(currency IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD')),
 identifier VARCHAR(180), market VARCHAR(180), source VARCHAR(180) NOT NULL,
 unsupported_terms VARCHAR(180), reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(owner_id,family_reference,version), UNIQUE(id,owner_id,currency),
 UNIQUE(id,owner_id,currency,kind,family_reference),
 FOREIGN KEY(supersedes_id,owner_id,currency) REFERENCES portfolio_assets(id,owner_id,currency) ON DELETE RESTRICT
);
CREATE TABLE portfolio_accounts (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 account_id BIGINT NOT NULL UNIQUE, currency CHAR(3) NOT NULL,
 reference CHAR(26) NOT NULL UNIQUE, platform_label VARCHAR(180) NOT NULL,
 source VARCHAR(180) NOT NULL, configured_date DATE NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id,currency), UNIQUE(id,owner_id),
 FOREIGN KEY(account_id,owner_id,currency) REFERENCES accounts(id,owner_id,currency_code) ON DELETE RESTRICT
);
CREATE TABLE portfolio_openings (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 portfolio_account_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 reference CHAR(26) NOT NULL UNIQUE, version INTEGER NOT NULL CHECK(version>0), supersedes_id BIGINT UNIQUE,
 event_kind VARCHAR(12) NOT NULL DEFAULT 'opening' CHECK(event_kind='opening'),
 as_of DATE NOT NULL CHECK(as_of BETWEEN DATE '0001-01-01' AND DATE '9998-12-31'),
 row_count INTEGER NOT NULL CHECK(row_count BETWEEN 0 AND 1000), source VARCHAR(180) NOT NULL, reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(portfolio_account_id,version), UNIQUE(id,owner_id,currency),
 FOREIGN KEY(portfolio_account_id,owner_id,currency) REFERENCES portfolio_accounts(id,owner_id,currency) ON DELETE RESTRICT,
 FOREIGN KEY(supersedes_id,owner_id,currency) REFERENCES portfolio_openings(id,owner_id,currency) ON DELETE RESTRICT
);
CREATE TABLE portfolio_opening_rows (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 opening_id BIGINT NOT NULL, asset_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 asset_family CHAR(26) NOT NULL, kind VARCHAR(24) NOT NULL,
 quantity NUMERIC CHECK(quantity>0 AND quantity<1000000000000000000 AND scale(quantity)<=12),
 unit_price NUMERIC CHECK(unit_price>=0 AND unit_price<1000000000000000000 AND scale(unit_price)<=12),
 manual_minor BIGINT CHECK(manual_minor>=0), basis_known BOOLEAN NOT NULL, basis_minor BIGINT CHECK(basis_minor>=0),
 UNIQUE(opening_id,asset_family),
 CHECK((basis_known AND basis_minor IS NOT NULL) OR (NOT basis_known AND basis_minor IS NULL)),
 CHECK((kind='manual_investment' AND quantity IS NULL AND unit_price IS NULL)
 OR (kind IN ('equity','unitized_fund') AND quantity IS NOT NULL AND manual_minor IS NULL)),
 FOREIGN KEY(opening_id,owner_id,currency) REFERENCES portfolio_openings(id,owner_id,currency) ON DELETE RESTRICT,
 FOREIGN KEY(asset_id,owner_id,currency,kind,asset_family) REFERENCES portfolio_assets(id,owner_id,currency,kind,family_reference) ON DELETE RESTRICT
);
CREATE TABLE portfolio_mode_previews (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 portfolio_account_id BIGINT NOT NULL, reference CHAR(26) NOT NULL UNIQUE,
 prior_id BIGINT, business_date DATE NOT NULL, expires_at TIMESTAMPTZ NOT NULL,
 payload JSONB NOT NULL, evidence JSONB NOT NULL, baseline_hash CHAR(64) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id,portfolio_account_id),
 FOREIGN KEY(portfolio_account_id,owner_id) REFERENCES portfolio_accounts(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE portfolio_modes (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 portfolio_account_id BIGINT NOT NULL, reference CHAR(26) NOT NULL UNIQUE,
 version INTEGER NOT NULL CHECK(version>0), supersedes_id BIGINT UNIQUE,
 preview_id BIGINT NOT NULL UNIQUE, mode VARCHAR(12) NOT NULL CHECK(mode IN ('detailed','aggregate')),
 effective_date DATE NOT NULL, platform_label VARCHAR(180) NOT NULL, source VARCHAR(180) NOT NULL,
 aggregate_minor BIGINT, aggregate_as_of DATE, reason VARCHAR(180) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(portfolio_account_id,version), UNIQUE(id,owner_id,portfolio_account_id),
 CHECK((mode='aggregate' AND aggregate_minor IS NOT NULL AND aggregate_as_of IS NOT NULL)
 OR (mode='detailed' AND aggregate_minor IS NULL AND aggregate_as_of IS NULL)),
 FOREIGN KEY(portfolio_account_id,owner_id) REFERENCES portfolio_accounts(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(supersedes_id,owner_id,portfolio_account_id) REFERENCES portfolio_modes(id,owner_id,portfolio_account_id) ON DELETE RESTRICT,
 FOREIGN KEY(preview_id,owner_id,portfolio_account_id) REFERENCES portfolio_mode_previews(id,owner_id,portfolio_account_id) ON DELETE RESTRICT
);
ALTER TABLE portfolio_mode_previews ADD CONSTRAINT preview_prior_owned FOREIGN KEY(prior_id,owner_id,portfolio_account_id) REFERENCES portfolio_modes(id,owner_id,portfolio_account_id) ON DELETE RESTRICT;
CREATE TABLE portfolio_requests (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 target_table VARCHAR(32) NOT NULL CHECK(target_table IN ('portfolio_assets','portfolio_accounts','portfolio_openings','portfolio_modes')),
 target_id BIGINT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(owner_id,idempotency_key)
);
CREATE INDEX portfolio_assets_choices ON portfolio_assets(owner_id,id DESC);
CREATE INDEX portfolio_openings_history ON portfolio_openings(portfolio_account_id,id DESC);
CREATE INDEX portfolio_modes_history ON portfolio_modes(portfolio_account_id,id DESC);
CREATE OR REPLACE FUNCTION hwo_portfolio_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior RECORD; parent RECORD; reviewed RECORD;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hwo:posting:owner:'||NEW.owner_id::text,0));
 IF TG_TABLE_NAME='portfolio_assets' THEN
  SELECT * INTO prior FROM portfolio_assets WHERE owner_id=NEW.owner_id AND family_reference=NEW.family_reference ORDER BY version DESC LIMIT 1;
  IF (prior.id IS NULL AND (NEW.version<>1 OR NEW.supersedes_id IS NOT NULL))
    OR (prior.id IS NOT NULL AND (NEW.supersedes_id IS DISTINCT FROM prior.id OR NEW.version<>prior.version+1 OR NEW.kind<>prior.kind OR NEW.currency<>prior.currency))
   THEN RAISE EXCEPTION 'Asset revision requires current owned prior and immutable kind/currency'; END IF;
 ELSIF TG_TABLE_NAME='portfolio_accounts' THEN
  SELECT * INTO parent FROM accounts WHERE id=NEW.account_id AND owner_id=NEW.owner_id FOR UPDATE;
  IF parent.id IS NULL OR parent.type<>'investment' OR parent.system_role IS NOT NULL OR parent.archived_at IS NOT NULL
   THEN RAISE EXCEPTION 'Portfolio requires an active owned investment account'; END IF;
 ELSIF TG_TABLE_NAME IN ('portfolio_openings','portfolio_modes','portfolio_mode_previews') THEN
  SELECT a.* INTO parent FROM portfolio_accounts p JOIN accounts a ON a.id=p.account_id AND a.owner_id=p.owner_id WHERE p.id=NEW.portfolio_account_id AND p.owner_id=NEW.owner_id FOR UPDATE OF a;
  IF parent.id IS NULL OR parent.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Portfolio account is unavailable or archived'; END IF;
  IF TG_TABLE_NAME='portfolio_openings' THEN
   SELECT * INTO prior FROM portfolio_openings WHERE portfolio_account_id=NEW.portfolio_account_id ORDER BY version DESC LIMIT 1;
   IF (prior.id IS NULL AND (NEW.version<>1 OR NEW.supersedes_id IS NOT NULL))
    OR (prior.id IS NOT NULL AND (NEW.supersedes_id IS DISTINCT FROM prior.id OR NEW.version<>prior.version+1 OR NEW.as_of<>prior.as_of))
    THEN RAISE EXCEPTION 'Opening correction requires current prior and original as-of date'; END IF;
  ELSIF TG_TABLE_NAME='portfolio_modes' THEN
   SELECT * INTO prior FROM portfolio_modes WHERE portfolio_account_id=NEW.portfolio_account_id ORDER BY version DESC LIMIT 1;
   SELECT * INTO reviewed FROM portfolio_mode_previews WHERE id=NEW.preview_id AND owner_id=NEW.owner_id AND portfolio_account_id=NEW.portfolio_account_id;
   IF (prior.id IS NULL AND (NEW.version<>1 OR NEW.supersedes_id IS NOT NULL))
    OR (prior.id IS NOT NULL AND (NEW.supersedes_id IS DISTINCT FROM prior.id OR NEW.version<>prior.version+1))
    OR reviewed.id IS NULL OR reviewed.prior_id IS DISTINCT FROM NEW.supersedes_id OR reviewed.business_date<>NEW.effective_date
    OR reviewed.payload->>'mode' IS DISTINCT FROM NEW.mode OR reviewed.payload->>'platform_label' IS DISTINCT FROM NEW.platform_label
    OR reviewed.payload->>'source' IS DISTINCT FROM NEW.source OR reviewed.payload->>'reason' IS DISTINCT FROM NEW.reason
    OR (reviewed.payload->>'aggregate_minor')::bigint IS DISTINCT FROM NEW.aggregate_minor
    OR (reviewed.payload->>'aggregate_as_of')::date IS DISTINCT FROM NEW.aggregate_as_of
    THEN RAISE EXCEPTION 'Mode requires its exact owned reviewed payload and current prior'; END IF;
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION hwo_portfolio_opening_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target BIGINT; expected INTEGER; actual INTEGER;
BEGIN
 IF TG_TABLE_NAME='portfolio_openings' THEN target:=NEW.id; ELSE target:=NEW.opening_id; END IF;
 SELECT row_count INTO expected FROM portfolio_openings WHERE id=target;
 SELECT count(*) INTO actual FROM portfolio_opening_rows WHERE opening_id=target;
 IF expected IS DISTINCT FROM actual THEN RAISE EXCEPTION 'Opening statement rows must commit completely'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER complete_opening_event AFTER INSERT ON portfolio_openings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_portfolio_opening_complete();
CREATE CONSTRAINT TRIGGER complete_opening_rows AFTER INSERT ON portfolio_opening_rows DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_portfolio_opening_complete();
SQL);
        foreach (['portfolio_assets', 'portfolio_accounts', 'portfolio_openings', 'portfolio_modes', 'portfolio_mode_previews'] as $table) {
            DB::unprepared("CREATE TRIGGER guard_{$table} BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION hwo_portfolio_version_guard()");
        }
        foreach (['portfolio_assets', 'portfolio_accounts', 'portfolio_openings', 'portfolio_opening_rows', 'portfolio_modes', 'portfolio_mode_previews', 'portfolio_requests'] as $table) {
            DB::unprepared("CREATE TRIGGER retain_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
    }

    public function down(): void
    {
        foreach (['portfolio_requests', 'portfolio_mode_previews', 'portfolio_modes', 'portfolio_opening_rows', 'portfolio_openings', 'portfolio_accounts', 'portfolio_assets'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retained portfolio evidence requires a forward migration; downgrade refused before DDL.');
            }
        }
        DB::unprepared('ALTER TABLE portfolio_mode_previews DROP CONSTRAINT preview_prior_owned; DROP TABLE portfolio_requests,portfolio_modes,portfolio_mode_previews,portfolio_opening_rows,portfolio_openings,portfolio_accounts,portfolio_assets; DROP FUNCTION hwo_portfolio_version_guard(); DROP FUNCTION hwo_portfolio_opening_complete(); ALTER TABLE accounts DROP CONSTRAINT portfolio_account_owner_currency;');
    }
};
