<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE portfolio_assets ADD COLUMN geography VARCHAR(180), ADD COLUMN geography_source VARCHAR(180);
ALTER TABLE portfolio_assets ADD CONSTRAINT geography_attributed CHECK((geography IS NULL)=(geography_source IS NULL));
ALTER TABLE portfolio_opening_rows ADD COLUMN acquired_on DATE CHECK(acquired_on BETWEEN DATE '0001-01-01' AND DATE '9998-12-31');
CREATE TABLE portfolio_prices (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 portfolio_account_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 asset_id BIGINT NOT NULL, asset_family CHAR(26) NOT NULL, asset_kind VARCHAR(24) NOT NULL,
 reference CHAR(26) NOT NULL UNIQUE, series_id CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version>0),
 supersedes_id BIGINT UNIQUE REFERENCES portfolio_prices(id) ON DELETE RESTRICT,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 unit_price NUMERIC NOT NULL CHECK(unit_price>=0 AND unit_price<1000000000000000000 AND scale(unit_price)<=12),
 observed_at TIMESTAMPTZ NOT NULL, effective_at TIMESTAMPTZ NOT NULL,
 source VARCHAR(180) NOT NULL, reason VARCHAR(180) NOT NULL, split_basis CHAR(64) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(owner_id,idempotency_key), UNIQUE(series_id,version),
 FOREIGN KEY(portfolio_account_id,owner_id,currency) REFERENCES portfolio_accounts(id,owner_id,currency) ON DELETE RESTRICT,
 FOREIGN KEY(asset_id,owner_id,currency,asset_kind,asset_family) REFERENCES portfolio_assets(id,owner_id,currency,kind,family_reference) ON DELETE RESTRICT
);
CREATE INDEX portfolio_prices_selection ON portfolio_prices(owner_id,portfolio_account_id,asset_family,effective_at,observed_at,id);
CREATE TABLE investment_valuation_runs (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 evidence JSONB NOT NULL, evidence_hash CHAR(64) NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(owner_id,idempotency_key)
);
CREATE OR REPLACE FUNCTION hwo_price_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p RECORD;
BEGIN
 IF NEW.supersedes_id IS NULL THEN
  IF NEW.version<>1 THEN RAISE EXCEPTION 'Initial price version must be one'; END IF;
 ELSE
  SELECT * INTO p FROM portfolio_prices WHERE id=NEW.supersedes_id FOR UPDATE;
  IF p.id IS NULL OR p.owner_id<>NEW.owner_id OR p.portfolio_account_id<>NEW.portfolio_account_id
   OR p.asset_family<>NEW.asset_family OR p.currency<>NEW.currency OR p.series_id<>NEW.series_id OR NEW.version<>p.version+1
   THEN RAISE EXCEPTION 'Price revision must retain owned account, asset family, currency and series'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER guard_price_version BEFORE INSERT ON portfolio_prices FOR EACH ROW EXECUTE FUNCTION hwo_price_version_guard();
CREATE TRIGGER retain_portfolio_prices BEFORE UPDATE OR DELETE ON portfolio_prices FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_investment_valuation_runs BEFORE UPDATE OR DELETE ON investment_valuation_runs FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
SQL);
    }

    public function down(): void
    {
        if (DB::table('portfolio_prices')->exists() || DB::table('investment_valuation_runs')->exists()
            || DB::table('portfolio_assets')->whereNotNull('geography')->exists() || DB::table('portfolio_opening_rows')->whereNotNull('acquired_on')->exists()) {
            throw new RuntimeException('Retained valuation evidence requires a forward migration; downgrade refused before DDL.');
        }
        DB::unprepared('DROP TABLE investment_valuation_runs,portfolio_prices; DROP FUNCTION hwo_price_version_guard(); ALTER TABLE portfolio_opening_rows DROP COLUMN acquired_on; ALTER TABLE portfolio_assets DROP CONSTRAINT geography_attributed, DROP COLUMN geography, DROP COLUMN geography_source;');
    }
};
