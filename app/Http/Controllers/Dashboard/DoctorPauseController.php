<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoctorPausedDate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DoctorPauseController extends Controller
{
    public function index(Request $request)
    {
        $doctorId = $request->user()->doctor_id;
        $fromDate = $request->get('from_date', Carbon::now('Africa/Khartoum')->toDateString());

        $dates = DoctorPausedDate::where('doctor_id', $doctorId)
            ->where('date', '>=', $fromDate)
            ->orderBy('date')
            ->get()
            ->map(fn ($d) => $d->date->format('Y-m-d'));

        return response()->json([
            'dates' => $dates->values()->all(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $doctorId = $request->user()->doctor_id;
        $date = Carbon::parse($request->date, 'Africa/Khartoum')->startOfDay()->toDateString();

        DoctorPausedDate::firstOrCreate(
            ['doctor_id' => $doctorId, 'date' => $date],
            ['doctor_id' => $doctorId, 'date' => $date]
        );

        return response()->json([
            'message' => 'Date paused successfully',
            'date' => $date,
        ], 201);
    }

    public function destroy(Request $request, string $date)
    {
        $doctorId = $request->user()->doctor_id;

        $deleted = DoctorPausedDate::where('doctor_id', $doctorId)
            ->where('date', $date)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'message' => 'Paused date not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Date unpaused successfully',
        ]);
    }
}
