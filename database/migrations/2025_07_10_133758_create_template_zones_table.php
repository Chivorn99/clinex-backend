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
        Schema::create('template_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->onDelete('cascade');
            $table->string('field_name');
            $table->enum('type', ['field', 'table']);
            $table->float('x');          // X coordinate (top-left)
            $table->float('y');          // Y coordinate (top-left)
            $table->float('width');      // Width of zone
            $table->float('height');     // Height of zone
            $table->integer('page');     // Page number
            $table->json('options')->nullable();  // Additional options for this zone
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_zones');
    }
};
