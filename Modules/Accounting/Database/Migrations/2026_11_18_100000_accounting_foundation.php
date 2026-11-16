<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('use_gfs')->default(false);
            $table->string('budget_phasing', 10)->default('quarter');
            $table->string('budget_control', 10)->default('warn');
            $table->timestamps();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open');
            $table->timestamps();
            $table->unique(['company_id', 'start_date']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->string('name', 30);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'number']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('gfs_code', 20)->nullable()->after('code');
            $table->boolean('is_postable')->default(true)->after('type');
            $table->boolean('is_budgetable')->default(false)->after('is_postable');
            $table->unique(['company_id', 'code']);
        });

        Schema::create('fund_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('kind', 20)->default('own');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('cash_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('kind', 10)->default('bank');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('bank_name', 150)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('fiscal_period_id')->nullable()->after('date')->constrained()->nullOnDelete();
            $table->string('entry_type', 20)->default('manual')->after('fiscal_period_id');
            $table->nullableMorphs('source');
            $table->decimal('total', 18, 2)->default(0)->after('description');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->index(['company_id', 'status', 'date']);
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->unsignedSmallInteger('line_no')->default(1)->after('journal_entry_id');
            $table->foreignId('department_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->foreignId('fund_source_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->foreignId('task_activity_id')->nullable()->after('fund_source_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->after('task_activity_id');
            $table->index(['account_id', 'journal_entry_id']);
        });

        if (! DB::table('erp_modules')->where('slug', 'accounting')->exists() && DB::table('erp_modules')->exists()) {
            DB::table('erp_modules')->insert(['slug' => 'accounting', 'name' => 'Accounting', 'description' => 'Chart of accounts, journals, general ledger and financial statements.',
                'icon' => 'bi-journal-bookmark', 'color' => 'primary', 'route_name' => 'accounting.dashboard', 'category' => 'Finance', 'sort_order' => 20, 'is_core' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('companies')->pluck('id') as $company) {
            if (DB::table('workflow_templates')->where('company_id', $company)->where('process', 'manual-journal')->exists()) {
                continue;
            }
            $role = DB::table('roles')->where('company_id', $company)->where('name', 'Chief Accountant')->value('id')
                ?? DB::table('roles')->where('company_id', $company)->where('name', 'company-admin')->value('id');
            if (! $role) {
                continue;
            }
            $template = DB::table('workflow_templates')->insertGetId(['company_id' => $company, 'process' => 'manual-journal', 'company_branch_id' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workflow_steps')->insert(['workflow_template_id' => $template, 'step_order' => 1, 'name' => 'Chief accountant', 'approver_type' => 'role', 'role_id' => $role,
                'same_branch' => false, 'allow_send_back' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('workflow_templates')->where('process', 'manual-journal')->delete();
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'journal_entry_id']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('fund_source_id');
            $table->dropConstrainedForeignId('task_activity_id');
            $table->dropColumn(['line_no', 'project_id']);
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status', 'date']);
            $table->dropConstrainedForeignId('fiscal_period_id');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropMorphs('source');
            $table->dropColumn(['entry_type', 'total', 'posted_at', 'decision_comment']);
        });
        Schema::dropIfExists('cash_bank_accounts');
        Schema::dropIfExists('fund_sources');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
            $table->dropColumn(['gfs_code', 'is_postable', 'is_budgetable']);
        });
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('accounting_settings');
    }
};
