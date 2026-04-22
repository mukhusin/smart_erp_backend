<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->decimal('minimum_stock', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_items'); }
};
