<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 15);
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 15);
            $table->string('name', 100);
            $table->string('type', 15)->default('store');
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('asset_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('depreciation_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('accumulated_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('residual_percent', 5, 2)->default(0);
            $table->decimal('capitalisation_threshold', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('item_category_id')->constrained()->restrictOnDelete();
            $table->string('type', 15)->default('store');
            $table->string('use', 10)->default('internal');
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->boolean('track_serial')->default(false);
            $table->decimal('min_level', 18, 4)->nullable();
            $table->decimal('max_level', 18, 4)->nullable();
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->decimal('last_cost', 18, 4)->nullable();
            $table->decimal('selling_price', 18, 2)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('factor', 18, 4);
            $table->unique(['item_id', 'unit_id']);
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->foreignId('company_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('main');
            $table->string('location', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('store_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('officer');
            $table->unique(['store_id', 'user_id']);
        });

        Schema::create('store_bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('store_bins')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unique(['store_id', 'code']);
        });

        Schema::create('item_store_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_level', 18, 4)->nullable();
            $table->decimal('max_level', 18, 4)->nullable();
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->foreignId('store_bin_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['item_id', 'store_id']);
        });
    }

    public function down(): void
    {
        foreach (['item_store_levels', 'store_bins', 'store_users', 'stores', 'item_units', 'items', 'item_categories', 'units'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
