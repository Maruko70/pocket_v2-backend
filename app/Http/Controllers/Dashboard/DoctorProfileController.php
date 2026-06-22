<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DoctorProfileController extends Controller
{
    public function show(Request $request)
    {
        $doctor = $request->user()->doctor;

        if (!$doctor) {
            return response()->json([
                'message' => 'Doctor profile not found',
            ], 404);
        }

        $doctor->load(['complexes', 'majors', 'weeklySchedule']);

        return response()->json([
            'doctor' => $doctor,
        ]);
    }
}
