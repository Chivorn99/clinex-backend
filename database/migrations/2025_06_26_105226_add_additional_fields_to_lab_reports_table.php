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
        Schema::table('lab_reports', function (Blueprint $table) {
            $table->string('stored_filename')->nullable()->after('original_filename');
            $table->unsignedBigInteger('file_size')->nullable()->after('storage_path');
            $table->string('mime_type')->nullable()->after('file_size');
            $table->string('patient_name')->nullable()->after('mime_type');
            $table->date('report_date')->nullable()->after('patient_name');
            $table->text('notes')->nullable()->after('report_date');
            $table->timestamp('uploaded_at')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_reports', function (Blueprint $table) {
            $table->dropColumn([
                'stored_filename',
                'file_size',
                'mime_type',
                'patient_name',
                'report_date',
                'notes',
                'uploaded_at'
            ]);
        });
    }
};
