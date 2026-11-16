<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategic_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->unsignedSmallInteger('start_year');
            $table->unsignedSmallInteger('end_year');
            $table->text('vision')->nullable();
            $table->text('mission')->nullable();
            $table->string('status', 10)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('strategic_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategic_plan_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('annual_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategic_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->unsignedSmallInteger('year');
            $table->date('start_date');
            $table->date('end_date');
            $table->date('submission_due')->nullable();
            $table->string('status', 10)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'year']);
        });

        Schema::create('department_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annual_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['annual_plan_id', 'department_id']);
        });

        Schema::table('task_activities', function (Blueprint $table) {
            $table->foreignId('annual_plan_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->foreignId('strategic_objective_id')->nullable()->after('annual_plan_id')->constrained()->nullOnDelete();
            $table->string('code', 20)->nullable()->after('strategic_objective_id');
            $table->string('output', 500)->nullable()->after('description');
            $table->string('target', 500)->nullable()->after('output');
            $table->string('kpi', 500)->nullable()->after('target');
            $table->string('quarters', 10)->nullable()->after('kpi');
        });

        if (! DB::table('erp_modules')->where('slug', 'planning')->exists() && DB::table('erp_modules')->exists()) {
            DB::table('erp_modules')->insert(['slug' => 'planning', 'name' => 'Planning', 'description' => 'Strategic plan, objectives and annual plans of department activities with targets and KPIs.',
                'icon' => 'bi-bullseye', 'color' => 'info', 'route_name' => 'planning.index', 'category' => 'Performance', 'sort_order' => 38, 'is_core' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('companies')->pluck('id') as $company) {
            if (DB::table('workflow_templates')->where('company_id', $company)->where('process', 'department-plan')->exists()) {
                continue;
            }
            $role = DB::table('roles')->where('company_id', $company)->where('name', 'director-general')->value('id')
                ?? DB::table('roles')->where('company_id', $company)->where('name', 'company-admin')->value('id');
            if (! $role) {
                continue;
            }
            $template = DB::table('workflow_templates')->insertGetId(['company_id' => $company, 'process' => 'department-plan', 'company_branch_id' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workflow_steps')->insert(['workflow_template_id' => $template, 'step_order' => 1, 'name' => 'Director general', 'approver_type' => 'role', 'role_id' => $role,
                'same_branch' => false, 'allow_send_back' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('workflow_templates')->where('process', 'department-plan')->delete();
        Schema::table('task_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('annual_plan_id');
            $table->dropConstrainedForeignId('strategic_objective_id');
            $table->dropColumn(['code', 'output', 'target', 'kpi', 'quarters']);
        });
        Schema::dropIfExists('department_plans');
        Schema::dropIfExists('annual_plans');
        Schema::dropIfExists('strategic_objectives');
        Schema::dropIfExists('strategic_plans');
    }
};
