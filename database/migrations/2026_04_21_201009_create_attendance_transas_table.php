<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_transas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('contract_id')->nullable()->constrained('employee_contracts')->onDelete('set null');
            $table->string('timetable_detail')->default('Official hours');
            $table->string('transaction_date');
            $table->boolean('pass_day')->default(false);
            $table->string('attendance_in_time')->nullable();
            $table->string('punch_in_time')->nullable();
            $table->string('punch_out_time')->nullable();
            $table->string('attendance_out_time')->nullable();
            $table->string('punch_in_time_loss')->nullable();
            $table->string('punch_out_time_loss')->nullable();
            $table->double('worktime_hour')->default(0);
            $table->double('worked_hour')->default(0);
            $table->double('basic_salary')->default(0);
            $table->double('amount_per_day')->default(0);
            $table->double('amount_earned_per_day')->default(0);
            $table->double('actual_hour')->default(0);
            $table->boolean('deduct_leave')->default(false);
            $table->double('lost_hour')->default(0);
            $table->double('lost_amount')->default(0);
            $table->double('percent')->nullable();
            $table->string('punch_out_status')->nullable();
            $table->string('punch_in_status')->nullable();
            $table->integer('punch_in_late_minutes')->default(0);
            $table->integer('punch_out_late_minutes')->default(0);
            $table->integer('overtime_hr')->default(0);
            $table->integer('overtime_minutes')->default(0);
            $table->boolean('has_overtime')->default(false);
            $table->boolean('is_claimed')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_transas');
    }
};
