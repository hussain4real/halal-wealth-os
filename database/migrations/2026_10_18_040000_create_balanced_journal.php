<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('system_role', 16)->nullable();
            $table->unique(['owner_id', 'currency_code', 'system_role']);
            $table->unique(['id', 'owner_id', 'currency_code']);
        });
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_system_role CHECK (system_role IS NULL OR (system_role IN ('income','expense','equity') AND type = 'internal_' || system_role))");
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->jsonb('request_payload');
            $table->string('kind', 16);
            $table->string('status', 16)->default('pending');
            $table->date('effective_date');
            $table->string('description', 180);
            $table->string('receipt_reference', 180)->nullable();
            $table->unsignedBigInteger('reverses_journal_id')->nullable()->unique();
            $table->unsignedBigInteger('replaces_journal_id')->nullable()->unique();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['owner_id', 'idempotency_key']);
            $table->unique(['id', 'owner_id']);
            $table->index(['owner_id', 'effective_date', 'id']);
            foreach (['reverses_journal_id', 'replaces_journal_id'] as $column) {
                $table->foreign([$column, 'owner_id'])->references(['id', 'owner_id'])->on('journal_entries')->restrictOnDelete();
            }
        });
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_states CHECK (status IN ('pending','posted','reversed') AND kind IN ('income','expense','transfer','opening','reversal','adjustment') AND ((kind = 'reversal') = (reverses_journal_id IS NOT NULL)) AND ((status = 'pending') = (posted_at IS NULL)))");
        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('account_id');
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->foreign(['journal_id', 'owner_id'])->references(['id', 'owner_id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['account_id', 'owner_id', 'currency_code'])->references(['id', 'owner_id', 'currency_code'])->on('accounts')->restrictOnDelete();
            $table->index(['owner_id', 'account_id', 'journal_id']);
        });
        Schema::create('opening_balance_postings', function (Blueprint $table) {
            $table->foreignId('opening_intent_id')->primary()->constrained('opening_balance_intents')->restrictOnDelete();
            $table->foreignId('journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION hwo_retain_financial_row() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Financial history is immutable'; END $$;
CREATE TRIGGER retain_postings BEFORE UPDATE OR DELETE ON postings FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_opening_links BEFORE UPDATE OR DELETE ON opening_balance_postings FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();
CREATE TRIGGER retain_opening_intents BEFORE UPDATE OR DELETE ON opening_balance_intents FOR EACH ROW EXECUTE FUNCTION hwo_retain_financial_row();

CREATE OR REPLACE FUNCTION hwo_journal_write_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Journal history cannot be deleted'; END IF;
 IF (to_jsonb(NEW) - 'status' - 'posted_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'posted_at')
 OR NOT ((OLD.status = 'pending' AND NEW.status = 'posted' AND NEW.posted_at IS NOT NULL)
 OR (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.posted_at = OLD.posted_at))
 THEN RAISE EXCEPTION 'Only posting or linked reversal may change journal state'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER journal_write_guard BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION hwo_journal_write_guard();

CREATE OR REPLACE FUNCTION hwo_posting_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_state text;
BEGIN
 SELECT status INTO current_state FROM journal_entries WHERE id = NEW.journal_id FOR UPDATE;
 IF current_state IS DISTINCT FROM 'pending' THEN RAISE EXCEPTION 'Postings require a pending journal'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER posting_insert_guard BEFORE INSERT ON postings FOR EACH ROW EXECUTE FUNCTION hwo_posting_insert_guard();

CREATE OR REPLACE FUNCTION hwo_check_journal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE j journal_entries%ROWTYPE; reversal_id bigint;
BEGIN
 IF TG_TABLE_NAME = 'postings' THEN SELECT * INTO j FROM journal_entries WHERE id = NEW.journal_id;
 ELSE SELECT * INTO j FROM journal_entries WHERE id = NEW.id; END IF;
 IF j.status = 'pending' THEN
  IF EXISTS (SELECT 1 FROM postings WHERE journal_id = j.id) THEN RAISE EXCEPTION 'Pending journals cannot retain financial legs'; END IF;
  RETURN NULL;
 END IF;
 IF (SELECT count(*) FROM postings WHERE journal_id = j.id) < 2
 OR EXISTS (SELECT 1 FROM postings WHERE journal_id = j.id GROUP BY currency_code HAVING sum(amount_minor::numeric) <> 0)
 THEN RAISE EXCEPTION 'Posted journals must balance per currency with at least two legs'; END IF;
 IF j.status = 'reversed' THEN
  SELECT id INTO reversal_id FROM journal_entries WHERE reverses_journal_id = j.id AND status = 'posted';
  IF reversal_id IS NULL THEN RAISE EXCEPTION 'Reversed journal requires its posted reversal'; END IF;
  IF EXISTS (SELECT 1 FROM postings WHERE journal_id IN (j.id, reversal_id) GROUP BY account_id, currency_code HAVING sum(amount_minor::numeric) <> 0)
  THEN RAISE EXCEPTION 'Reversal must cancel every original account movement'; END IF;
 END IF;
 IF j.kind = 'reversal' AND NOT EXISTS (SELECT 1 FROM journal_entries WHERE id = j.reverses_journal_id AND status = 'reversed' AND kind <> 'reversal')
 THEN RAISE EXCEPTION 'Reversal requires a reversed original'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER check_journal AFTER INSERT OR UPDATE ON journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_check_journal();
CREATE CONSTRAINT TRIGGER check_posting_journal AFTER INSERT ON postings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_check_journal();

CREATE OR REPLACE FUNCTION hwo_check_opening_link() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE valid boolean;
BEGIN
 SELECT j.kind = 'opening' AND j.status IN ('posted','reversed') AND j.owner_id = a.owner_id
 AND (SELECT coalesce(sum(p.amount_minor::numeric),0) FROM postings p WHERE p.journal_id = j.id AND p.account_id = a.id) = i.amount_minor
 INTO valid FROM opening_balance_intents i JOIN accounts a ON a.id = i.account_id JOIN journal_entries j ON j.id = NEW.journal_id WHERE i.id = NEW.opening_intent_id;
 IF valid IS DISTINCT FROM true THEN RAISE EXCEPTION 'Opening link must match its owned exact opening journal'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER check_opening_link AFTER INSERT ON opening_balance_postings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION hwo_check_opening_link();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_postings');
        Schema::dropIfExists('postings');
        Schema::dropIfExists('journal_entries');
        foreach (['hwo_check_opening_link', 'hwo_check_journal', 'hwo_posting_insert_guard', 'hwo_journal_write_guard'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
        DB::statement('DROP TRIGGER IF EXISTS retain_opening_intents ON opening_balance_intents');
        DB::statement('DROP FUNCTION IF EXISTS hwo_retain_financial_row()');
        DB::statement('ALTER TABLE accounts DROP CONSTRAINT accounts_system_role');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'currency_code', 'system_role']);
            $table->dropUnique(['id', 'owner_id', 'currency_code']);
            $table->dropColumn('system_role');
        });
    }
};
