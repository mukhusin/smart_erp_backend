<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic approval workflows, configured per company by the company admin.
 * Processes (what can be approved) are defined in code: Modules/Workflow/Services/WorkflowCatalog.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One workflow per process per company; optional per-branch override (company_branch_id set).
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('process', 60);
            $table->foreignId('company_branch_id')->nullable()->constrained('company_branches')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'process', 'company_branch_id'], 'workflow_templates_unique');
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('name', 100);
            /** role | user | department_head */
            $table->string('approver_type', 30);
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            /** For role approvers: only users of the request's branch. */
            $table->boolean('same_branch')->default(false);
            $table->boolean('allow_send_back')->default(true);
            $table->string('approve_label', 40)->nullable();
            $table->string('reject_label', 40)->nullable();
            $table->timestamps();

            $table->unique(['workflow_template_id', 'step_order']);
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('process', 60);
            $table->foreignId('workflow_template_id')->nullable()->constrained('workflow_templates')->nullOnDelete();
            $table->morphs('subject');
            /** Used to resolve branch and department-head approvers. */
            $table->foreignId('company_branch_id')->nullable()->constrained('company_branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            /** pending | approved | rejected | cancelled */
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('current_step_order')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // Steps copied from the template when the request starts (template edits never affect running requests).
        Schema::create('workflow_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('name', 100);
            $table->string('approver_type', 30);
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('same_branch')->default(false);
            $table->boolean('allow_send_back')->default(true);
            $table->string('approve_label', 40)->nullable();
            $table->string('reject_label', 40)->nullable();
            /** waiting | pending | approved | rejected | sent_back | skipped */
            $table->string('status', 20)->default('waiting');
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('acted_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['workflow_instance_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instance_steps');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_templates');
    }
};
