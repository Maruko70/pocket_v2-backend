<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complex extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'bio',
        'logo',
        'phone',
        'address',
        'booking_method',
        'is_active',
    ];

    protected $attributes = [
        'booking_method' => 'slots',
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public const BOOKING_METHOD_SLOTS = 'slots';
    public const BOOKING_METHOD_TICKETS = 'tickets';

    public function ticketConfigs(): HasMany
    {
        return $this->hasMany(DoctorTicketConfig::class);
    }

    public function usesTicketBooking(): bool
    {
        return $this->booking_method === self::BOOKING_METHOD_TICKETS;
    }

    public function usesSlotBooking(): bool
    {
        return $this->booking_method === self::BOOKING_METHOD_SLOTS;
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_complex');
    }

    public function majors(): BelongsToMany
    {
        return $this->belongsToMany(Major::class, 'complex_major');
    }

    public function doctorComplexMajors(): HasMany
    {
        return $this->hasMany(DoctorComplexMajor::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function chips(): HasMany
    {
        return $this->hasMany(ComplexChip::class)->orderBy('sort_order')->orderBy('id');
    }
}

