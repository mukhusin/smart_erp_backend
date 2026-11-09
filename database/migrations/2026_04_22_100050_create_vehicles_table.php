<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number', 30);
            $table->string('make', 80);
            $table->string('model', 80);
            $table->smallInteger('year')->nullable();
            $table->string('color', 40)->nullable();
            $table->string('type', 30)->default('sedan');
            $table->string('status', 20)->default('available');
            $table->foreignId('current_driver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 15, 2)->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->date('inspection_expiry')->nullable();
            $table->text('description')->nullable();
            $table->unique(['company_id', 'plate_number']);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('vehicles'); }
};
