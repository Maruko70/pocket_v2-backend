<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'platform' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();

        DeviceToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'token' => $validated['token'],
            ],
            [
                'platform' => $validated['platform'] ?? null,
                'last_seen_at' => now(),
                'is_active' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Device token saved',
        ]);
    }
}

