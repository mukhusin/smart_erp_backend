<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('table_name', 60);
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['company_id', 'source', 'table_name', 'legacy_id'], 'legacy_ids_unique');
            $table->index(['company_id', 'source', 'table_name', 'target_id'], 'legacy_ids_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_ids');
    }
};
