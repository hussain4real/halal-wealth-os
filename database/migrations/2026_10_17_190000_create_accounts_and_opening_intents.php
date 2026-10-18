<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('type', 32);
            $table->char('currency_code', 3);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_id', 'archived_at']);
            $table->unique(['id', 'currency_code']);
        });
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_currency_supported CHECK (currency_code IN ('QAR','NGN','USD','EUR','GBP','JPY','KWD'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_supported CHECK (type IN ('bank','cash','investment','liability','internal_clearing','internal_equity','internal_income','internal_expense'))");
        Schema::create('opening_balance_intents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->timestampsTz();
            $table->foreign(['account_id', 'currency_code'])->references(['id', 'currency_code'])->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_intents');
        Schema::dropIfExists('accounts');
    }
};
