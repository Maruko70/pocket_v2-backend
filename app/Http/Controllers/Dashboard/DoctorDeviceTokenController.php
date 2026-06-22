<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoctorDeviceToken;
use Illuminate\Http\Request;

class DoctorDeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'platform' => ['nullable', 'string', 'max:50'],
        ]);

        $doctor = $request->user()->doctor;

        if (!$doctor) {
            return response()->json([
                'message' => 'Doctor profile not found',
            ], 404);
        }

        DoctorDeviceToken::updateOrCreate(
            [
                'doctor_id' => $doctor->id,
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
