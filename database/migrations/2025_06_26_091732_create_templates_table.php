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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Daily Blood Count V2"
            $table->text('description')->nullable();
            $table->json('header_boundary')->nullable(); // Stores { y_coordinate: 0.25 }
            $table->json('footer_boundary')->nullable(); // Stores { y_coordinate: 0.85 }
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
