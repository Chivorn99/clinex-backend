<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'patient_id',
        'name',
        'age',
        'gender',
        'phone',
    ];

    /**
     * Get the lab reports for this patient.
     */
    public function labReports()
    {
        return $this->hasMany(LabReport::class);
    }
}
