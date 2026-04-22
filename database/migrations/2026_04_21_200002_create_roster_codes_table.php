<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('code');
            $table->string('duty');
            $table->string('description')->nullable();
            $table->boolean('can_punch')->default(false);
            $table->boolean('pass_day')->default(false);
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->double('work_hour')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_codes');
    }
};
