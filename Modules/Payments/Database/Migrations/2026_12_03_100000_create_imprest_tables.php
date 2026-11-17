<?php

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Hr\Support\DefaultLeaveTypes;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('kind', 10)->default('payment')->after('number');
            $table->foreignId('holder_employee_id')->nullable()->after('kind')->constrained('employees')->nullOnDelete();
            $table->date('retire_by')->nullable()->after('needed_by');
        });

        Schema::table('payment_settings', function (Blueprint $table) {
            $table->foreignId('imprest_account_id')->nullable()->after('withholding_account_id')->constrained('accounts')->nullOnDelete();
            $table->unsignedSmallInteger('imprest_retire_days')->default(14)->after('imprest_account_id');
            $table->boolean('imprest_one_open')->default(true)->after('imprest_retire_days');
        });

        Schema::create('imprest_retirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('payment_request_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->text('notes')->nullable();
            $table->decimal('advanced', 18, 2);
            $table->decimal('spent', 18, 2)->default(0);
            $table->decimal('returned', 18, 2)->default(0);
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('return_reference', 100)->nullable();
            $table->string('status', 15)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('imprest_retirement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imprest_retirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->constrained()->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::table('payment_attachments', function (Blueprint $table) {
            $table->foreignId('imprest_retirement_id')->nullable()->after('payment_request_id')->constrained()->cascadeOnDelete();
        });

        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, fn () => DefaultLeaveTypes::payments());
        }
    }

    public function down(): void
    {
        Schema::table('payment_attachments', fn (Blueprint $table) => $table->dropConstrainedForeignId('imprest_retirement_id'));
        Schema::dropIfExists('imprest_retirement_lines');
        Schema::dropIfExists('imprest_retirements');
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imprest_account_id');
            $table->dropColumn(['imprest_retire_days', 'imprest_one_open']);
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holder_employee_id');
            $table->dropColumn(['kind', 'retire_by']);
        });
    }
};
