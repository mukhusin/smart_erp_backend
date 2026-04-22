<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('module_slug', 60);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id','module_slug']);
            $table->foreign('module_slug')->references('slug')->on('erp_modules')->onDelete('cascade');
        });
    }
    public function down(): void { Schema::dropIfExists('company_modules'); }
};
