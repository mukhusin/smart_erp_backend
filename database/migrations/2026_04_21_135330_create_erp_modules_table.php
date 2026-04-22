<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('erp_modules', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 60)->default('bi-grid');
            $table->string('color', 30)->default('primary');
            $table->string('route_name')->nullable();
            $table->string('category', 60)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('erp_modules'); }
};
