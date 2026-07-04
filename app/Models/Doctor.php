<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'bio',
        'image',
        'status',
        'featured_sort',
    ];

    protected $casts = [
        'status' => 'string',
        'featured_sort' => 'integer',
    ];

    public function complexes(): BelongsToMany
    {
        return $this->belongsToMany(Complex::class, 'doctor_complex');
    }

    public function majors(): BelongsToMany
    {
        return $this->belongsToMany(Major::class, 'doctor_major');
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

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DoctorStatusHistory::class);
    }

    public function weeklySchedule(): HasMany
    {
        return $this->hasMany(DoctorWeeklySchedule::class);
    }

    public function ticketConfigs(): HasMany
    {
        return $this->hasMany(DoctorTicketConfig::class);
    }

    public function complexChips(): BelongsToMany
    {
        return $this->belongsToMany(ComplexChip::class, 'doctor_complex_chip');
    }

    public function pausedDates(): HasMany
    {
        return $this->hasMany(DoctorPausedDate::class);
    }

    public function doctorUser(): HasOne
    {
        return $this->hasOne(DoctorUser::class);
    }

    public function unavailabilityRequests(): HasMany
    {
        return $this->hasMany(DoctorUnavailabilityRequest::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DoctorDeviceToken::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function getAverageRatingAttribute(): float
    {
        return $this->reviews()->avg('rating') ?? 0;
    }

    public function getReviewsCountAttribute(): int
    {
        return $this->reviews()->count();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeFeatured($query)
    {
        return $query->whereNotNull('featured_sort')->orderBy('featured_sort');
    }
}

