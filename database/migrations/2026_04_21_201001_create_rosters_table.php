<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->foreignId('section_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->string('title');
            $table->string('type');  // duty, annual_leave
            $table->string('from_date');
            $table->string('to_date');
            $table->string('roster_year')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('status')->default('pending');
            $table->boolean('is_active')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
