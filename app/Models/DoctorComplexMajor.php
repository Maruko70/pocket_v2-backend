<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorComplexMajor extends Model
{
    protected $table = 'doctor_complex_major';

    protected $fillable = [
        'doctor_id',
        'complex_id',
        'major_id',
        'works',
    ];

    protected $casts = [
        'works' => 'boolean',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    public function major(): BelongsTo
    {
        return $this->belongsTo(Major::class);
    }
}
