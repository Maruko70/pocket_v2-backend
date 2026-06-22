<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class DoctorUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'doctor_users';

    protected $fillable = [
        'doctor_id',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }
}
