<?php

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\DefaultRoles;
use Modules\Hr\Support\DefaultLeaveTypes;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imprest_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->json('deduction_ids')->nullable();
            $table->decimal('amount', 18, 2);
            $table->unsignedSmallInteger('months');
            $table->date('start_month');
            $table->decimal('recovered', 18, 2)->default(0);
            $table->string('status', 15)->default('active');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('imprest_recovery_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imprest_recovery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_sheet_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['imprest_recovery_id', 'payroll_sheet_id'], 'imprest_recovery_sheet_unique');
        });

        Schema::create('petty_cash_floats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('custodian_id')->constrained('users')->restrictOnDelete();
            $table->decimal('float_amount', 18, 2);
            $table->decimal('max_voucher', 18, 2)->nullable();
            $table->foreignId('replenish_from_id')->nullable()->constrained('cash_bank_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'cash_bank_account_id']);
        });

        Schema::create('petty_cash_replenishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('petty_cash_float_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->string('status', 15)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('petty_cash_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('petty_cash_float_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('payee', 150);
            $table->string('description', 255);
            $table->foreignId('budget_item_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name', 200)->nullable();
            $table->string('status', 15)->default('pending');
            $table->boolean('over_budget')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('petty_cash_replenishment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['petty_cash_float_id', 'status']);
        });

        if (! Company::query()->exists()) {
            return;
        }
        DefaultRoles::syncPermissions();
        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, fn () => DefaultLeaveTypes::payments());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_vouchers');
        Schema::dropIfExists('petty_cash_replenishments');
        Schema::dropIfExists('petty_cash_floats');
        Schema::dropIfExists('imprest_recovery_entries');
        Schema::dropIfExists('imprest_recoveries');
    }
};
