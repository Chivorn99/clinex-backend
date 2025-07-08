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
        'description',
        'processor_id',  // Add this - Google AI Processor ID
        'mappings',     
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'mappings' => 'array', // Cast JSON to array automatically
    ];

    /**
     * Get all of the fields for the Template.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }

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

    /**
     * Get mapping for a specific field
     */
    public function getMappingFor($fieldName)
    {
        return $this->mappings[$fieldName] ?? null;
    }

    /**
     * Set mapping for a specific field
     */
    public function setMappingFor($fieldName, $mapping)
    {
        $mappings = $this->mappings ?? [];
        $mappings[$fieldName] = $mapping;
        $this->mappings = $mappings;
        return $this;
    }

    /**
     * Get all available field mappings
     */
    public function getAvailableFields()
    {
        return array_keys($this->mappings ?? []);
    }
}
