<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('receivable_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedSmallInteger('default_terms_days')->default(30);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->string('kind', 15)->default('company');
            $table->string('tin', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->unsignedSmallInteger('terms_days')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->date('due_date');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid', 18, 2)->default(0);
            $table->decimal('credited', 18, 2)->default(0);
            $table->string('status', 15)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'due_date']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 2)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('kind', 10);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payer_name', 200);
            $table->date('date');
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->string('method', 10)->default('cash');
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('notes', 500)->nullable();
            $table->string('status', 15)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'date']);
        });

        Schema::create('receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('budget_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->text('reason');
            $table->string('status', 15)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('receipt_allocations');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('receivable_settings');
    }
};
