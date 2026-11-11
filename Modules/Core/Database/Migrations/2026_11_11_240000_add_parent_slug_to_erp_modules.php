<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_modules', function (Blueprint $table) {
            $table->string('parent_slug', 60)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('erp_modules', fn (Blueprint $table) => $table->dropColumn('parent_slug'));
    }
};
