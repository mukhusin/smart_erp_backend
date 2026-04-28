<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saccos_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saccos_member_id')->constrained('saccos_members')->onDelete('cascade');
            $table->foreignId('payroll_sheet_detail_id')->nullable()->constrained('payroll_sheet_details')->onDelete('set null');
            $table->double('amount')->default(0);
            $table->date('date_contributed')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saccos_contributions');
    }
};
