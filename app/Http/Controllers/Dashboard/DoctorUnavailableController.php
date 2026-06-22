<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoctorUnavailabilityRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DoctorUnavailableController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'auto_reactivate' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $doctor = $request->user()->doctor;

        if (!$doctor) {
            return response()->json([
                'message' => 'Doctor profile not found',
            ], 404);
        }

        $fromDate = Carbon::parse($request->from_date, 'Africa/Khartoum')->startOfDay();
        $toDate = $request->filled('to_date')
            ? Carbon::parse($request->to_date, 'Africa/Khartoum')->endOfDay()
            : null;
        $autoReactivate = (bool) $request->input('auto_reactivate', $toDate !== null);

        $unavailabilityRequest = DoctorUnavailabilityRequest::create([
            'doctor_id' => $doctor->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate?->toDateString(),
            'auto_reactivate' => $autoReactivate,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        return response()->json([
            'message' => 'Request submitted. Awaiting admin approval.',
            'request' => [
                'id' => $unavailabilityRequest->id,
                'from_date' => $fromDate->toIso8601String(),
                'to_date' => $toDate?->toIso8601String(),
                'auto_reactivate' => $autoReactivate,
                'status' => $unavailabilityRequest->status,
            ],
        ]);
    }
}
