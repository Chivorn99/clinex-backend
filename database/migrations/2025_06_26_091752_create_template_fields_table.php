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
        Schema::create('template_fields', function (Blueprint $table) {
            $table->id();

            // Foreign key to link this field to a specific template
            $table->foreignId('template_id')->constrained()->onDelete('cascade');

            $table->string('field_name'); // e.g., "patient_name", "wbc", "hematology_header"

            // Defines the type of field: 'static', 'anchor' (a section header), or 'relative' (data within a section)
            $table->enum('type', ['static', 'anchor', 'relative']);

            // For 'static' and 'anchor' types. Stores {x, y, width, height}
            $table->json('coordinates')->nullable();

            // For 'relative' types. Links to the 'anchor' type field.
            $table->foreignId('anchor_id')->nullable()->constrained('template_fields')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_fields');
    }
};
