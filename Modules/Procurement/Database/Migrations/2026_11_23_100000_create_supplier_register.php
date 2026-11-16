<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = ['bill_payments', 'bills', 'purchase_order_items', 'purchase_orders', 'purchase_request_items', 'purchase_requests', 'stock_movements', 'inventory_items', 'inventory_categories', 'suppliers'];

    public function up(): void
    {
        foreach (self::LEGACY as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Legacy table {$table} has data; move it before running this migration.");
            }
        }
        Schema::disableForeignKeyConstraints();
        foreach (self::LEGACY as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->string('kind', 15)->default('company');
            $table->string('tin', 30)->nullable();
            $table->string('vrn', 30)->nullable();
            $table->string('registration_number', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('special_group', 15)->nullable();
            $table->json('categories')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('blacklisted')->default(false);
            $table->string('blacklist_reason', 500)->nullable();
            $table->date('blacklisted_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'tin']);
        });

        Schema::create('supplier_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 100);
            $table->string('branch', 100)->nullable();
            $table->string('account_name', 150);
            $table->string('account_number', 50);
            $table->string('swift_code', 20)->nullable();
            $table->string('currency', 3)->default('TZS');
            $table->boolean('is_default')->default(false);
            $table->string('status', 15)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('number', 100)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_documents');
        Schema::dropIfExists('supplier_bank_accounts');
        Schema::dropIfExists('suppliers');
    }
};
