<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE shariah_assessments (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT, actor_is_admin BOOLEAN NOT NULL,
 asset_id BIGINT NOT NULL, asset_family CHAR(26) NOT NULL, asset_currency CHAR(3) NOT NULL, asset_kind VARCHAR(24) NOT NULL,
 reference CHAR(26) NOT NULL UNIQUE, series_id CHAR(26) NOT NULL, version INTEGER NOT NULL CHECK(version>0),
 supersedes_id BIGINT UNIQUE REFERENCES shariah_assessments(id) ON DELETE RESTRICT,
 idempotency_key VARCHAR(120) NOT NULL, payload_hash CHAR(64) NOT NULL,
 provenance VARCHAR(24) NOT NULL CHECK(provenance IN ('source_claim','personal_note','ai_suggestion')),
 provider VARCHAR(180) NOT NULL CHECK(length(trim(provider))>0), methodology_version VARCHAR(180) NOT NULL,
 classification VARCHAR(24) NOT NULL CHECK(classification IN ('compliant','non_compliant','questionable','pending_review')),
 effective_on DATE NOT NULL CHECK(effective_on BETWEEN DATE '0001-01-01' AND DATE '9998-12-31'),
 review_due_on DATE CHECK(review_due_on BETWEEN effective_on AND DATE '9998-12-31'),
 evidence_refs JSONB NOT NULL CHECK(jsonb_typeof(evidence_refs)='array' AND jsonb_array_length(evidence_refs)<=12),
 source_url TEXT, business_findings TEXT, financial_findings TEXT, notes TEXT,
 reason VARCHAR(180) NOT NULL CHECK(length(trim(reason))>0), recorded_at TIMESTAMPTZ NOT NULL,
 CHECK(actor_id=owner_id),
 CHECK(classification='pending_review' OR (provenance='source_claim' AND jsonb_array_length(evidence_refs)>0 AND length(trim(methodology_version))>0)),
 UNIQUE(owner_id,idempotency_key), UNIQUE(series_id,version),
 FOREIGN KEY(asset_id,owner_id,asset_currency,asset_kind,asset_family) REFERENCES portfolio_assets(id,owner_id,currency,kind,family_reference) ON DELETE RESTRICT
);
CREATE INDEX shariah_assessment_selection ON shariah_assessments(owner_id,asset_id,series_id,version,effective_on,recorded_at);
CREATE INDEX shariah_assessment_history ON shariah_assessments(owner_id,asset_family,id);
CREATE OR REPLACE FUNCTION hwo_assessment_version_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p RECORD;
BEGIN
 IF NEW.supersedes_id IS NULL THEN
  IF NEW.version<>1 THEN RAISE EXCEPTION 'Initial assessment version must be one'; END IF;
 ELSE
  SELECT * INTO p FROM shariah_assessments WHERE id=NEW.supersedes_id FOR UPDATE;
  IF p.id IS NULL OR p.owner_id<>NEW.owner_id OR p.asset_id<>NEW.asset_id OR p.asset_family<>NEW.asset_family
   OR p.series_id<>NEW.series_id OR p.provider<>NEW.provider OR p.provenance<>NEW.provenance
   OR NEW.version<>p.version+1 OR NEW.effective_on<p.effective_on OR NEW.recorded_at<p.recorded_at
   THEN RAISE EXCEPTION 'Assessment revision must retain owned exact asset, source and series with ordered dates'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER guard_assessment_version BEFORE INSERT ON shariah_assessments FOR EACH ROW EXECUTE FUNCTION hwo_assessment_version_guard();
CREATE TRIGGER retain_shariah_assessments BEFORE UPDATE OR DELETE ON shariah_assessments FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
SQL);
    }

    public function down(): void
    {
        if (DB::table('shariah_assessments')->exists()) {
            throw new RuntimeException('Retained assessment evidence requires a forward migration; downgrade refused before DDL.');
        }
        DB::unprepared('DROP TABLE shariah_assessments; DROP FUNCTION hwo_assessment_version_guard();');
    }
};
