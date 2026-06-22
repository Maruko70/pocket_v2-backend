<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\Request;

class DoctorReservationController extends Controller
{
    public function index(Request $request)
    {
        $doctorId = $request->user()->doctor_id;

        $query = Reservation::with(['user', 'doctor', 'complex'])
            ->where('doctor_id', $doctorId);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $reservations = $query->orderBy('date', 'desc')
            ->orderBy('time', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'reservations' => $reservations->items(),
            'pagination' => [
                'current_page' => $reservations->currentPage(),
                'per_page' => $reservations->perPage(),
                'total' => $reservations->total(),
                'last_page' => $reservations->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, $id)
    {
        $doctorId = $request->user()->doctor_id;

        $reservation = Reservation::with(['user', 'doctor', 'complex'])
            ->where('doctor_id', $doctorId)
            ->findOrFail($id);

        return response()->json([
            'reservation' => $reservation,
        ]);
    }
}
