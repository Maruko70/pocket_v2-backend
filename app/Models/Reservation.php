<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'doctor_id',
        'complex_id',
        'date',
        'time',
        'ticket_number',
        'patient_name',
        'patient_phone',
        'status',
        'created_by_admin_user_id',
        'created_source',
    ];

    protected $casts = [
        'date' => 'date',
        'time' => 'datetime:H:i',
        'ticket_number' => 'integer',
        'status' => 'string',
    ];

    /**
     * Ensure appointment date is serialized as Y-m-d so clients show the correct calendar day.
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        if (isset($array['date']) && $this->date) {
            $array['date'] = $this->date->format('Y-m-d');
        }
        return $array;
    }

    public function scopeTicketReservations($query)
    {
        return $query->whereNotNull('ticket_number');
    }

    public function scopeSlotReservations($query)
    {
        return $query->whereNull('ticket_number');
    }

    public function isTicketReservation(): bool
    {
        return $this->ticket_number !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }
}

