<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GuestUserSeeder extends Seeder
{
    public function run(): void
    {
        $phone = (string) config('guest.user_phone');
        $name = (string) config('guest.user_name');

        $user = User::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'password' => Hash::make(Str::random(48)),
            ]
        );

        // Ensure it's usable for ownership (not an actual end-user account).
        if (property_exists($user, 'status') || array_key_exists('status', $user->getAttributes())) {
            $user->forceFill([
                'status' => 'active',
                'verified_at' => $user->verified_at ?? now(),
            ])->save();
        }

        $this->command?->info('Guest user ensured: ' . $phone);
    }
}

