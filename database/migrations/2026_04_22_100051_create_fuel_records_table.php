<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('fuel_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('liters', 8, 2);
            $table->decimal('cost_per_liter', 8, 2)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->integer('odometer_reading')->nullable();
            $table->foreignId('filled_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('fuel_records'); }
};
