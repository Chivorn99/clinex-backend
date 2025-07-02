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
        Schema::create('ocr_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('original_text');
            $table->string('corrected_text');
            $table->enum('correction_type', ['test_name', 'value', 'patient_info']);
            $table->integer('frequency')->default(1);
            $table->integer('confidence_score')->default(90);
            $table->timestamps();
            
            // Add indexes for performance
            $table->index(['original_text', 'correction_type']);
            $table->index('confidence_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ocr_corrections');
    }
};
