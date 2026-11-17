<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('retained_earnings_account_id')->nullable()->constrained('accounts')->nullOnDelete();
        });

        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->foreignId('closing_journal_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('close_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('decision_comment')->nullable();
        });

        Schema::create('fiscal_year_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->string('report', 40);
            $table->string('title', 150);
            $table->string('subtitle', 255)->nullable();
            $table->json('columns');
            $table->json('rows');
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'report']);
        });

        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('kind', 15);
            $table->date('date');
            $table->string('notes', 1000)->nullable();
            $table->string('status', 15)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('opening_balance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opening_balance_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 15);
            $table->string('reference', 60);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fund_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('party', 200)->nullable();
            $table->date('document_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('description', 255)->nullable();
            $table->nullableMorphs('target');
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'reference']);
        });

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_id')->nullable()->change();
            $table->unsignedBigInteger('department_id')->nullable()->change();
        });
        Schema::table('payment_request_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_lines');
        Schema::dropIfExists('opening_balances');
        Schema::dropIfExists('fiscal_year_statements');
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closing_journal_id');
            $table->dropConstrainedForeignId('close_requested_by');
            $table->dropColumn(['closed_at', 'decision_comment']);
        });
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retained_earnings_account_id');
        });
    }
};
