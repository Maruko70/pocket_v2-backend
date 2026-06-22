<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'doctor_status_history';

    protected $fillable = [
        'doctor_id',
        'status',
        'from_date',
        'to_date',
        'is_auto_reactivate',
    ];

    protected $casts = [
        'from_date' => 'datetime',
        'to_date' => 'datetime',
        'is_auto_reactivate' => 'boolean',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}

