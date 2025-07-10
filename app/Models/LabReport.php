<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabReport extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'original_filename',
        'stored_filename',
        'storage_path',
        'file_size',
        'mime_type',
        'patient_name',
        'patient_id', 
        'template_id',
        'report_date',
        'notes',
        'status',
        'processing_error',
        'uploaded_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'report_date' => 'date',
        'uploaded_at' => 'datetime',
        'file_size' => 'integer',
    ];

    /**
     * Get all of the extractedData for the LabReport.
     */
    public function extractedData(): HasMany
    {
        return $this->hasMany(ExtractedData::class);
    }

    /**
     * Get the template that this lab report uses.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Get the full path to the stored file.
     */
    public function getFullPath(): string
    {
        return storage_path('app/private/' . $this->storage_path);
    }

    /**
     * Check if the file exists on disk.
     */
    public function fileExists(): bool
    {
        return file_exists($this->getFullPath());
    }

    /**
     * Get human readable file size.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeUploadedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('uploaded_at', [$startDate, $endDate]);
    }

    /**
     * Get the patient that owns this lab report.
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}