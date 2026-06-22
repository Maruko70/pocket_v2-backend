<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Major extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'icon',
    ];

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_major');
    }

    public function complexes(): BelongsToMany
    {
        return $this->belongsToMany(Complex::class, 'complex_major');
    }

    public function doctorComplexMajors(): HasMany
    {
        return $this->hasMany(DoctorComplexMajor::class);
    }
}

