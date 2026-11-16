<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'department_id', 'year']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('task_activity_id')->nullable()->constrained('task_activities')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 15)->default('todo');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('checklist')->nullable();
            $table->string('recurrence', 10)->nullable();
            $table->boolean('next_created')->default(false);
            $table->string('review_comment', 1000)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });

        Schema::create('task_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->unsignedTinyInteger('progress');
            $table->string('status', 15)->default('pending');
            $table->string('decision_comment', 1000)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('hr_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('task_max_step')->default(30)->after('retirement_age');
        });

        if (! DB::table('erp_modules')->where('slug', 'tasks')->exists() && DB::table('erp_modules')->exists()) {
            DB::table('erp_modules')->insert(['slug' => 'tasks', 'name' => 'Tasks', 'description' => 'Department activities, tasks assigned or added by staff, progress approved by the supervisor.',
                'icon' => 'bi-list-check', 'color' => 'primary', 'route_name' => 'tasks.index', 'category' => 'Performance', 'sort_order' => 39, 'is_core' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('companies')->pluck('id') as $company) {
            if (DB::table('workflow_templates')->where('company_id', $company)->where('process', 'task-progress')->exists()) {
                continue;
            }
            $template = DB::table('workflow_templates')->insertGetId(['company_id' => $company, 'process' => 'task-progress', 'company_branch_id' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('workflow_steps')->insert(['workflow_template_id' => $template, 'step_order' => 1, 'name' => 'Supervisor', 'approver_type' => 'supervisor', 'same_branch' => false,
                'allow_send_back' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_updates');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_activities');
        Schema::table('hr_settings', fn (Blueprint $table) => $table->dropColumn('task_max_step'));
    }
};
