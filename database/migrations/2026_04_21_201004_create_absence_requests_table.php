<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absence_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('last_updated_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->date('from_date');
            $table->date('to_date');
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            $table->integer('number_of_days');
            $table->text('description');
            $table->string('address')->nullable();
            $table->boolean('has_per_diem')->default(false);
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_requests');
    }
};
