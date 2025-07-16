<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LabReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'patient_id',
        'uploaded_by',
        'verified_by',
        'original_filename',
        'stored_filename',
        'storage_path',
        'file_size',
        'mime_type',
        'file_hash',
        'report_date',
        'notes',
        'status',
        'processing_started_at',
        'processing_completed_at',
        'verified_at',
        'processing_error',
    ];

    protected $casts = [
        'report_date' => 'date',
        'file_size' => 'integer',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReportBatch::class, 'batch_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function extractedData(): HasMany
    {
        return $this->hasMany(ExtractedData::class);
    }

    public function extractedLabInfo(): HasOne
    {
        return $this->hasOne(ExtractedLabInfo::class);
    }

    public function getFullPath(): string
    {
        return storage_path('app/private/' . $this->storage_path);
    }

    public function fileExists(): bool
    {
        return file_exists($this->getFullPath());
    }

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

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeUploadedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeByBatch($query, int $batchId)  // ✅ Add this
    {
        return $query->where('batch_id', $batchId);
    }
}