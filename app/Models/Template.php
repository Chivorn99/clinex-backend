<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Template extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'processor_id',
        'structure_data',
        'field_mappings',
        'mappings', 
        'status',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'structure_data' => 'array',
        'field_mappings' => 'array',
        'mappings' => 'array',
    ];

    /**
     * Get all lab reports that use this template
     */
    public function labReports(): HasMany
    {
        return $this->hasMany(LabReport::class, 'template_id');
    }

    /**
     * Scope to filter by processor ID
     */
    public function scopeByProcessor($query, $processorId)
    {
        return $query->where('processor_id', $processorId);
    }


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
