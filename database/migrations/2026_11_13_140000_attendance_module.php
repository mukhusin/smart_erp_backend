<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('source', 20)->default('none');
            $table->text('connection')->nullable();
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->unsignedSmallInteger('sync_days')->default(60);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('emp_code', 50);
            $table->dateTime('punch_time');
            $table->string('punch_state', 20)->nullable();
            $table->string('verify_type', 20)->nullable();
            $table->string('area_alias')->nullable();
            $table->string('terminal_sn', 100)->nullable();
            $table->string('terminal_alias')->nullable();
            $table->string('source', 20)->default('device');
            $table->unsignedBigInteger('external_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'emp_code', 'punch_time']);
            $table->index(['employee_id', 'punch_time']);
        });

        Schema::create('attendance_sync_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('sync_date');
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('punch_count')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'sync_date']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('attendance_code', 50)->nullable()->after('employee_no');
            $table->boolean('has_attendance')->default(false)->after('has_annual_leave');
        });

        Schema::table('attendance_transas', function (Blueprint $table) {
            $table->date('transaction_date')->change();
            $table->string('status', 60)->nullable()->after('punch_in_status');
            $table->string('area_alias')->nullable()->after('status');
            $table->string('in_area_alias')->nullable()->after('area_alias');
            $table->integer('punch_in_late_hour')->default(0)->after('punch_in_late_minutes');
            $table->integer('punch_out_late_hour')->default(0)->after('punch_out_late_minutes');
            $table->string('payroll_date_remark')->nullable()->after('description');
            $table->string('planned_code', 20)->nullable()->after('timetable_detail');
            $table->double('accepted_hour')->default(0)->after('lost_amount');
            $table->unique(['employee_id', 'transaction_date']);
        });

        Schema::table('attendance_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('claim_reason_id')->nullable()->change();
            $table->date('claim_date')->change();
            $table->dateTime('approved_at')->nullable()->change();
            $table->foreignId('requested_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
        });

        foreach (['excuse_of_duties', 'admitted_employees'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->date('start_date');
                $table->date('end_date');
                $table->unsignedSmallInteger('number_of_days');
                $table->string('hospital_name')->nullable();
                if ($name === 'excuse_of_duties') {
                    $table->string('doctor_name')->nullable();
                    $table->string('patient_address')->nullable();
                    $table->text('reason')->nullable();
                } else {
                    $table->text('admission_reason')->nullable();
                    $table->text('release_reason')->nullable();
                    $table->string('remark')->nullable();
                }
                $table->string('status', 20)->default('active');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['employee_id', 'start_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admitted_employees');
        Schema::dropIfExists('excuse_of_duties');
        if (Schema::hasColumn('attendance_claims', 'requested_by')) {
            Schema::table('attendance_claims', fn (Blueprint $table) => $table->dropConstrainedForeignId('requested_by'));
        }
        Schema::table('attendance_transas', function (Blueprint $table) {
            $table->index('employee_id');
            $table->dropUnique(['employee_id', 'transaction_date']);
            $table->dropColumn(['status', 'area_alias', 'in_area_alias', 'punch_in_late_hour', 'punch_out_late_hour', 'payroll_date_remark', 'planned_code', 'accepted_hour']);
        });
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['attendance_code', 'has_attendance']));
        Schema::dropIfExists('attendance_sync_days');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_settings');
    }
};
