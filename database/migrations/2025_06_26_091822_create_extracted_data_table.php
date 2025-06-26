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
        Schema::create('extracted_data', function (Blueprint $table) {
            $table->id();

            // Foreign key to link this data to a specific uploaded report
            $table->foreignId('lab_report_id')->constrained()->onDelete('cascade');

            $table->string('section')->nullable(); // e.g., "hematology", "biochemistry"
            $table->string('field_name'); // e.g., "wbc", "lym"
            $table->text('value')->nullable();
            $table->json('coordinates')->nullable(); // The coordinates where this data was found
            $table->float('confidence_score')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extracted_data');
    }
};
