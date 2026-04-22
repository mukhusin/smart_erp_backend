<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employee_dependants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('fullname');
            $table->string('profile_image')->nullable();
            $table->string('gender');
            $table->string('address')->nullable();
            $table->string('date_of_birth')->nullable();
            $table->string('birth_certificate')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('relationship');
            $table->boolean('is_next_of_kin')->default(false);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('employee_dependants'); }
};
