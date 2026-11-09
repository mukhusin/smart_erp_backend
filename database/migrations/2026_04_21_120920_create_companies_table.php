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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            /** Used in the login link (/login?org=slug) and later for subdomains. */
            $table->string('slug', 60)->unique();
            $table->string('short_name')->nullable();
            $table->string('registration_number')->nullable()->unique();
            $table->string('tin')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Tanzania');
            $table->string('logo')->nullable();
            $table->string('login_background')->nullable();
            /** Theme colors (#rrggbb). Null = the ERP default. */
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('currency')->default('TZS');
            $table->string('fiscal_year_start')->default('01-01');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
