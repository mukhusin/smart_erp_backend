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
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 2);
            $table->decimal('book_balance', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status', 15)->default('draft');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'cash_bank_account_id', 'status'], 'bank_recs_bank_status_idx');
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->foreignId('journal_entry_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['cash_bank_account_id', 'date']);
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained()->nullOnDelete();
        });

        if (! Company::query()->exists()) {
            return;
        }
        DefaultRoles::syncPermissions();
        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, fn () => DefaultLeaveTypes::manualJournal());
        }
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('bank_reconciliation_id'));
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
