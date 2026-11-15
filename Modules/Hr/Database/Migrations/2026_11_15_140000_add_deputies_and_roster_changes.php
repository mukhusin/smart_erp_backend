<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('head_of_departments', function (Blueprint $table) {
            /** A deputy (HRMS assistant HOD): sees and approves the same as the head. */
            $table->boolean('is_deputy')->default(false)->after('level');
        });

        Schema::create('roster_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('roster_id')->constrained('rosters')->cascadeOnDelete();
            /** Changed days: employee_id, date, roster_code_id (null clears), with the code before. */
            $table->json('items');
            $table->string('reason', 500)->nullable();
            /** pending | applied | rejected | cancelled */
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_comment', 1000)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'roster_id', 'status']);
        });

        foreach (DB::table('companies')->pluck('id') as $company) {
            foreach (['duty-roster' => 'Head of department', 'duty-roster-change' => 'Head of department'] as $process => $step) {
                if (DB::table('workflow_templates')->where('company_id', $company)->where('process', $process)->exists()) {
                    continue;
                }
                $template = DB::table('workflow_templates')->insertGetId(['company_id' => $company, 'process' => $process, 'company_branch_id' => null, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now()]);
                DB::table('workflow_steps')->insert(['workflow_template_id' => $template, 'step_order' => 1, 'name' => $step, 'approver_type' => 'department_head',
                    'same_branch' => false, 'allow_send_back' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_change_requests');
        Schema::table('head_of_departments', fn (Blueprint $table) => $table->dropColumn('is_deputy'));
    }
};
