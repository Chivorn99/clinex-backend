<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Siemens ADVIA - Hematology"
            $table->text('description')->nullable();

            // 🔥 NEW: The Google AI Processor ID this template uses
            $table->string('processor_id');

            // 🔥 NEW: The JSON mapping rules
            $table->json('mappings');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
