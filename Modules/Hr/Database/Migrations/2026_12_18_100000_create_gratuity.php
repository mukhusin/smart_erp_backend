<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gratuity_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->decimal('rate_percent', 5, 2)->default(25);
            $table->string('base', 10)->default('basic');
            $table->json('contract_types')->nullable();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('liability_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gratuity_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('gratuity_accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('base_amount', 18, 2);
            $table->decimal('rate_percent', 5, 2);
            $table->decimal('amount', 18, 2);
            $table->foreignId('gratuity_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['payroll_sheet_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gratuity_accruals');
        Schema::dropIfExists('gratuity_settlements');
        Schema::dropIfExists('gratuity_settings');
    }
};
