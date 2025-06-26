<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateField extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'template_id',
        'field_name',
        'type',
        'coordinates',
        'anchor_id',
    ];

    /**
     * Get the template that owns the TemplateField.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Get the anchor field that this field is relative to.
     */
    public function anchor(): BelongsTo
    {
        return $this->belongsTo(TemplateField::class, 'anchor_id');
    }
}
