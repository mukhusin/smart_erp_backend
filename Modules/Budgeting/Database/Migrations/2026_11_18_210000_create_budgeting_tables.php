<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('annual_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('phasing', 10)->default('quarter');
            $table->string('status', 10)->default('draft');
            $table->date('submission_due')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'fiscal_year_id']);
        });

        Schema::create('department_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('draft');
            $table->decimal('ceiling', 18, 2)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['budget_id', 'department_id']);
        });

        Schema::create('budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('fund_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('quantity', 14, 2)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('frequency', 10, 2)->default(1);
            $table->decimal('original_amount', 18, 2)->default(0);
            $table->decimal('revised_amount', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['budget_id', 'department_id']);
            $table->index(['account_id', 'department_id']);
        });

        Schema::create('budget_item_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('period');
            $table->decimal('amount', 18, 2)->default(0);
            $table->unique(['budget_item_id', 'period']);
        });

        Schema::create('budget_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 15)->default('transfer');
            $table->foreignId('from_item_id')->nullable()->constrained('budget_items')->nullOnDelete();
            $table->foreignId('to_item_id')->constrained('budget_items')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->text('reason');
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->constrained()->cascadeOnDelete();
            $table->morphs('source');
            $table->decimal('amount', 18, 2);
            $table->string('status', 10)->default('reserved');
            $table->timestamps();
            $table->index(['budget_item_id', 'status']);
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('budget_item_id')->nullable()->after('project_id');
            $table->index('budget_item_id');
        });

        if (! DB::table('erp_modules')->where('slug', 'budgeting')->exists() && DB::table('erp_modules')->exists()) {
            DB::table('erp_modules')->insert(['slug' => 'budgeting', 'name' => 'Budgeting', 'description' => 'Budgets from the annual plan, transfers, budget control and budget vs actual.',
                'icon' => 'bi-wallet2', 'color' => 'success', 'route_name' => 'budgeting.index', 'category' => 'Finance', 'sort_order' => 21, 'is_core' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('companies')->pluck('id') as $company) {
            $finance = DB::table('roles')->where('company_id', $company)->where('name', 'Chief Accountant')->value('id')
                ?? DB::table('roles')->where('company_id', $company)->where('name', 'company-admin')->value('id');
            $director = DB::table('roles')->where('company_id', $company)->where('name', 'director-general')->value('id') ?? $finance;
            if (! $finance) {
                continue;
            }
            $defaults = [
                'department-budget' => [['Finance', 'role', $finance]],
                'budget-approval' => [['Director general', 'role', $director]],
                'budget-transfer' => [['Finance', 'role', $finance]],
            ];
            foreach ($defaults as $process => $steps) {
                if (DB::table('workflow_templates')->where('company_id', $company)->where('process', $process)->exists()) {
                    continue;
                }
                $template = DB::table('workflow_templates')->insertGetId(['company_id' => $company, 'process' => $process, 'company_branch_id' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($steps as $i => [$name, $type, $role]) {
                    DB::table('workflow_steps')->insert(['workflow_template_id' => $template, 'step_order' => $i + 1, 'name' => $name, 'approver_type' => $type, 'role_id' => $role,
                        'same_branch' => false, 'allow_send_back' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('workflow_templates')->whereIn('process', ['department-budget', 'budget-approval', 'budget-transfer'])->delete();
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['budget_item_id']);
            $table->dropColumn('budget_item_id');
        });
        Schema::dropIfExists('budget_commitments');
        Schema::dropIfExists('budget_transfers');
        Schema::dropIfExists('budget_item_periods');
        Schema::dropIfExists('budget_items');
        Schema::dropIfExists('department_budgets');
        Schema::dropIfExists('budgets');
    }
};
