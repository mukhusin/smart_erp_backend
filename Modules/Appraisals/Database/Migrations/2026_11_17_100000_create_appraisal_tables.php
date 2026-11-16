<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appraisal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scale', 10)->default('percent');
            $table->boolean('mid_year')->default(true);
            $table->json('parts');
            $table->json('bands');
            $table->timestamps();
            $table->unique('company_id');
        });

        Schema::create('appraisal_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('appraisal_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->date('start_date');
            $table->date('end_date');
            $table->date('mid_year_date')->nullable();
            $table->date('self_due')->nullable();
            $table->date('review_due')->nullable();
            $table->string('status', 10)->default('draft');
            $table->json('config');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('appraisals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 15)->default('open');
            $table->text('mid_year_comment')->nullable();
            $table->foreignId('mid_year_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mid_year_at')->nullable();
            $table->text('self_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->string('rating', 60)->nullable();
            $table->text('employee_comment')->nullable();
            $table->boolean('employee_agrees')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['appraisal_cycle_id', 'employee_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('appraisal_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appraisal_id')->constrained()->cascadeOnDelete();
            $table->string('rater', 12);
            $table->string('part', 20);
            $table->foreignId('appraisal_competency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['appraisal_id', 'rater']);
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $exists = DB::table('workflow_templates')->where('company_id', $companyId)->where('process', 'appraisal')->exists();
            if ($exists) {
                continue;
            }
            $hr = DB::table('roles')->where('company_id', $companyId)->where('name', 'hr-manager')->value('id')
                ?? DB::table('roles')->where('company_id', $companyId)->where('name', 'hr-officer')->value('id');
            $id = DB::table('workflow_templates')->insertGetId(['company_id' => $companyId, 'process' => 'appraisal', 'company_branch_id' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $steps = [
                ['step_order' => 1, 'name' => 'Supervisor', 'approver_type' => 'supervisor', 'role_id' => null],
                ['step_order' => 2, 'name' => 'Head of department', 'approver_type' => 'department_head', 'role_id' => null],
            ];
            if ($hr) {
                $steps[] = ['step_order' => 3, 'name' => 'HR moderation', 'approver_type' => 'role', 'role_id' => $hr];
            }
            foreach ($steps as $s) {
                DB::table('workflow_steps')->insert($s + ['workflow_template_id' => $id, 'same_branch' => false, 'allow_send_back' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('workflow_templates')->where('process', 'appraisal')->delete();
        Schema::dropIfExists('appraisal_scores');
        Schema::dropIfExists('appraisals');
        Schema::dropIfExists('appraisal_cycles');
        Schema::dropIfExists('appraisal_competencies');
        Schema::dropIfExists('appraisal_settings');
    }
};
