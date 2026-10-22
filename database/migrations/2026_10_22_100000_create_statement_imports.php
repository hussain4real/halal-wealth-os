<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE statement_adapters (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, name VARCHAR(180) NOT NULL,
 config JSONB NOT NULL, digest CHAR(64) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,digest)
);
CREATE TABLE statement_sources (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, namespace VARCHAR(180) NOT NULL,
 account_id BIGINT NOT NULL, currency CHAR(3) NOT NULL,
 digest CHAR(64) NOT NULL, byte_count INTEGER NOT NULL CHECK(byte_count BETWEEN 1 AND 262144),
 source_base64 TEXT NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,namespace,account_id,digest),
 FOREIGN KEY(account_id,owner_id,currency) REFERENCES accounts(id,owner_id,currency_code) ON DELETE RESTRICT
);
CREATE TABLE statement_snapshots (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, source_id BIGINT NOT NULL, adapter_id BIGINT NOT NULL,
 digest CHAR(64) NOT NULL, interpretation JSONB NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,source_id,digest),
 FOREIGN KEY(source_id,owner_id) REFERENCES statement_sources(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(adapter_id,owner_id) REFERENCES statement_adapters(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE statement_receipts (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 reference CHAR(26) NOT NULL UNIQUE, idempotency_key VARCHAR(120) NOT NULL,
 payload_hash CHAR(64) NOT NULL, request_payload JSONB NOT NULL,
 snapshot_id BIGINT NOT NULL, outcomes JSONB NOT NULL,
 corrects_receipt_id BIGINT, corrects_token VARCHAR(8), reason VARCHAR(180),
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,idempotency_key), UNIQUE(owner_id,corrects_receipt_id,corrects_token),
 CHECK((corrects_receipt_id IS NULL)=(corrects_token IS NULL)),
 FOREIGN KEY(snapshot_id,owner_id) REFERENCES statement_snapshots(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(corrects_receipt_id,owner_id) REFERENCES statement_receipts(id,owner_id) ON DELETE RESTRICT
);
CREATE TABLE statement_claims (
 id BIGSERIAL PRIMARY KEY, owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 logical_key CHAR(64) NOT NULL, fact_hash CHAR(64) NOT NULL,
 receipt_id BIGINT NOT NULL, token VARCHAR(8) NOT NULL,
 journal_id BIGINT, event_id BIGINT, portfolio_id BIGINT, currency CHAR(3) NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(id,owner_id), UNIQUE(owner_id,logical_key),
 CHECK(num_nonnulls(journal_id,event_id)=1), CHECK((event_id IS NULL)=(portfolio_id IS NULL)),
 FOREIGN KEY(receipt_id,owner_id) REFERENCES statement_receipts(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_id,owner_id) REFERENCES journal_entries(id,owner_id) ON DELETE RESTRICT,
 FOREIGN KEY(event_id,owner_id,portfolio_id,currency) REFERENCES investment_events(id,owner_id,portfolio_account_id,currency) ON DELETE RESTRICT
);
CREATE INDEX statement_source_history ON statement_sources(owner_id,id);
CREATE INDEX statement_snapshot_history ON statement_snapshots(owner_id,source_id,id);
CREATE INDEX statement_receipt_history ON statement_receipts(owner_id,snapshot_id,id);
CREATE INDEX statement_claim_receipt ON statement_claims(owner_id,receipt_id);
CREATE INDEX statement_claim_fact ON statement_claims(owner_id,fact_hash);
SQL);
        foreach (['adapters', 'sources', 'snapshots', 'receipts', 'claims'] as $table) {
            DB::statement("CREATE TRIGGER retain_statement_{$table} BEFORE UPDATE OR DELETE ON statement_{$table} FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row()");
        }
    }

    public function down(): void
    {
        foreach (['adapters', 'sources', 'snapshots', 'receipts', 'claims'] as $table) {
            if (DB::table('statement_'.$table)->exists()) {
                throw new RuntimeException('Retained statement import evidence: downgrade refused before DDL.');
            }
        }
        foreach (['claims', 'receipts', 'snapshots', 'sources', 'adapters'] as $table) {
            DB::statement('DROP TABLE statement_'.$table);
        }
    }
};
