<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorTicketConfig extends Model
{
    protected $fillable = [
        'doctor_id',
        'complex_id',
        'from_ticket',
        'to_ticket',
        'is_active',
    ];

    protected $casts = [
        'from_ticket' => 'integer',
        'to_ticket' => 'integer',
        'is_active' => 'boolean',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    public function ticketCount(): int
    {
        return max(0, $this->to_ticket - $this->from_ticket + 1);
    }
}
