<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_transa_id')->constrained('attendance_transas')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('claim_reason_id')->constrained('attendance_claim_reasons')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('claim_date');
            $table->string('approved_at')->nullable();
            $table->boolean('set_lost_hr_zero')->default(false);
            $table->double('hr_accepted')->default(0);
            $table->double('lost_hour')->default(0);
            $table->double('lost_amount')->default(0);
            $table->double('percent')->default(0);
            $table->string('remark')->nullable();
            $table->text('description');
            $table->text('comment')->nullable();
            $table->string('claim_status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_claims');
    }
};
