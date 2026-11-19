<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('locale', 5)->nullable());
        Schema::table('companies', fn (Blueprint $table) => $table->string('default_locale', 5)->default('en'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('default_locale'));
    }
};
