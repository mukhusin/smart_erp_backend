<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null only for platform admins (system owner), who belong to no company.
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('company_branch_id')->nullable()->after('company_id')->constrained('company_branches')->onDelete('set null');
            $table->boolean('is_active')->default(true)->after('remember_token');
            $table->string('avatar')->nullable()->after('is_active');
            $table->boolean('is_platform_admin')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['company_branch_id']);
            $table->dropColumn(['company_id', 'company_branch_id', 'is_active', 'avatar', 'is_platform_admin']);
        });
    }
};
