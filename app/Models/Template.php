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
        'header_boundary',
        'footer_boundary',
    ];

    /**
     * Get all of the fields for the Template.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class);
    }
}
