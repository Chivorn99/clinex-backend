<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractedData extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'lab_report_id',
        'section',
        'field_name',
        'value',
        'coordinates',
        'confidence_score',
        'is_verified',
    ];

    /**
     * Get the labReport that owns the ExtractedData.
     */
    public function labReport(): BelongsTo
    {
        return $this->belongsTo(LabReport::class);
    }
}
