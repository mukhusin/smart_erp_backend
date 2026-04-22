<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('set null');
            $table->foreignId('company_branch_id')->nullable()->constrained('company_branches')->onDelete('set null');
            $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('section_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->foreignId('job_position_id')->nullable()->constrained('job_positions')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('employee_no')->unique();
            $table->string('fname');
            $table->string('mname')->nullable();
            $table->string('lname');
            $table->string('profile_image')->nullable();
            $table->string('gender');
            $table->date('date_of_birth')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('home_address')->nullable();
            $table->string('email')->nullable();
            $table->string('tin')->nullable();
            $table->string('nationality')->default('Tanzanian');
            $table->string('id_number')->nullable();
            $table->string('id_type')->default('NIN');
            $table->string('marital_status')->nullable();
            $table->string('emp_title')->nullable();
            $table->date('employment_date')->nullable();
            $table->string('place_of_recruitment')->default('Dar es Salaam');
            $table->string('emp_type')->nullable();
            $table->string('emp_category')->default('Primary');
            $table->date('termination_date')->nullable();
            $table->string('nssf_no')->nullable();
            $table->string('nhif_no')->nullable();
            $table->string('wcf_no')->nullable();
            $table->string('employment_status')->default('probation');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('contract_status')->default('pending');
            $table->date('probation_start')->nullable();
            $table->integer('probation_period')->nullable();
            $table->date('probation_end')->nullable();
            $table->string('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('employees'); }
};
