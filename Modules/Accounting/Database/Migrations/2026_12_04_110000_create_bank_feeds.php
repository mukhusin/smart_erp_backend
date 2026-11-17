<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_bank_accounts', function (Blueprint $table) {
            $table->text('feed_config')->nullable();
            $table->boolean('feed_enabled')->default(false);
            $table->timestamp('feed_checked_at')->nullable();
            $table->string('feed_error', 500)->nullable();
        });

        Schema::create('bank_feed_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->date('date');
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->foreignId('bank_statement_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['cash_bank_account_id', 'external_id']);
            $table->index(['cash_bank_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_feed_transactions');
        Schema::table('cash_bank_accounts', fn (Blueprint $table) => $table->dropColumn(['feed_config', 'feed_enabled', 'feed_checked_at', 'feed_error']));
    }
};
