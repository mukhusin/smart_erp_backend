<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('vehicle_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 30)->default('routine');
            $table->text('description')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('service_provider', 150)->nullable();
            $table->date('next_service_date')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('vehicle_maintenance_logs'); }
};
