<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('model', 100)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_expiration_date')->nullable();
            $table->string('status', 20)->default('available');
            $table->text('description')->nullable();
            $table->string('cover_image')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('assets'); }
};
