<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorAvailability extends Model
{
    use HasFactory;

    protected $table = 'doctor_availability';

    protected $fillable = [
        'doctor_id',
        'complex_id',
        'date',
        'start_time',
        'end_time',
        'is_booked',
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'is_booked' => 'boolean',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }
}

