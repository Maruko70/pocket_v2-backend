<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PasswordResetOtp extends Model
{
    protected $fillable = [
        'phone',
        'otp',
        'expires_at',
        'used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used' => 'boolean',
    ];

    public function isValid(): bool
    {
        return !$this->used && $this->expires_at->isFuture();
    }

    public function markAsUsed(): void
    {
        $this->update(['used' => true]);
    }

    public static function generateOtp(): string
    {
        return str_pad((string) rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function createForPhone(string $phone): self
    {
        // Invalidate any existing unused OTPs for this phone
        self::where('phone', $phone)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->update(['used' => true]);

        return self::create([
            'phone' => $phone,
            'otp' => self::generateOtp(),
            'expires_at' => now()->addMinutes(10), // OTP expires in 10 minutes
            'used' => false,
        ]);
    }

    public static function verify(string $phone, string $otp): ?self
    {
        return self::where('phone', $phone)
            ->where('otp', $otp)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();
    }
}
