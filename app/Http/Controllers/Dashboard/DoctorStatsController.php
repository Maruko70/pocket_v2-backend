<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DoctorStatsController extends Controller
{
    public function index(Request $request)
    {
        $doctorId = $request->user()->doctor_id;
        $now = Carbon::now('Africa/Khartoum');

        $doctor = $request->user()->doctor;
        $doctor->load(['reviews']);

        $upcomingCount = Reservation::where('doctor_id', $doctorId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($now) {
                $q->where('date', '>', $now->toDateString())
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('date', '=', $now->toDateString())
                            ->where(function ($q3) use ($now) {
                                $q3->whereNull('time')
                                    ->orWhere('time', '>', $now->toTimeString());
                            });
                    });
            })
            ->count();

        $pastCount = Reservation::where('doctor_id', $doctorId)
            ->where(function ($q) use ($now) {
                $q->where('date', '<', $now->toDateString())
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('date', '=', $now->toDateString())
                            ->whereNotNull('time')
                            ->where('time', '<=', $now->toTimeString());
                    });
            })
            ->count();

        $completedThisMonth = Reservation::where('doctor_id', $doctorId)
            ->where('status', 'completed')
            ->whereMonth('date', $now->month)
            ->whereYear('date', $now->year)
            ->count();

        return response()->json([
            'average_rating' => round($doctor->average_rating, 1),
            'reviews_count' => $doctor->reviews_count,
            'upcoming_count' => $upcomingCount,
            'past_count' => $pastCount,
            'completed_this_month' => $completedThisMonth,
        ]);
    }
}
