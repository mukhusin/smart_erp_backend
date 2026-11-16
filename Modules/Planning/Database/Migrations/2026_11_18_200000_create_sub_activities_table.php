<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_activity_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('title', 255);
            $table->string('output', 500)->nullable();
            $table->string('quarters', 10)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_activities');
    }
};
