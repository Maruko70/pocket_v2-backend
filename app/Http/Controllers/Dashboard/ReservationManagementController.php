<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\DoctorAvailability;
use App\Models\DoctorPausedDate;
use App\Models\DoctorTicketConfig;
use App\Models\DoctorWeeklySchedule;
use App\Models\Reservation;
use App\Models\User;
use App\Rules\SudanPhoneNumber;
use App\Services\NotificationService;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationManagementController extends Controller
{
    public function availableDays(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
        ]);

        $availableDays = DoctorWeeklySchedule::where('doctor_id', $request->doctor_id)
            ->where('complex_id', $request->complex_id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('day_of_week')
            ->toArray();

        return response()->json([
            'available_days' => $availableDays, // 0=Sunday..6=Saturday
        ]);
    }

    public function pausedDates(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|exists:doctors,id',
            'from_date' => 'nullable|date',
        ]);

        $fromDate = $request->get('from_date', Carbon::now('Africa/Khartoum')->toDateString());

        $dates = DoctorPausedDate::where('doctor_id', $request->doctor_id)
            ->where('date', '>=', $fromDate)
            ->orderBy('date')
            ->get()
            ->map(fn ($d) => $d->date->format('Y-m-d'));

        return response()->json([
            'dates' => $dates->values()->all(),
        ]);
    }

    public function slotAvailability(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
        ]);

        $complex = Complex::where('id', $request->complex_id)->where('is_active', true)->first();
        if (!$complex || !$complex->usesSlotBooking()) {
            return response()->json([
                'availabilities' => [],
            ]);
        }

        $date = Carbon::parse($request->date, 'Africa/Khartoum');
        $dayOfWeek = $date->dayOfWeek; // 0=Sunday..6=Saturday
        $dateString = $date->toDateString();

        if (DoctorPausedDate::where('doctor_id', $request->doctor_id)->where('date', $dateString)->exists()) {
            return response()->json([
                'availabilities' => [],
                'is_paused' => true,
            ]);
        }

        $weeklySchedules = DoctorWeeklySchedule::where('doctor_id', $request->doctor_id)
            ->where('complex_id', $request->complex_id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get();

        $bookedSlots = DoctorAvailability::where('doctor_id', $request->doctor_id)
            ->where('complex_id', $request->complex_id)
            ->where('date', $dateString)
            ->where('is_booked', true)
            ->pluck('start_time')
            ->map(fn ($time) => Carbon::parse($time)->format('H:i'))
            ->toArray();

        $slotDuration = 15;
        $timeSlots = [];

        foreach ($weeklySchedules as $schedule) {
            $startTime = Carbon::parse($schedule->start_time);
            $endTime = Carbon::parse($schedule->end_time);

            $currentTime = $startTime->copy();
            while ($currentTime->lt($endTime)) {
                $timeSlot = $currentTime->format('H:i');
                $timeSlots[] = [
                    'time' => $timeSlot,
                    'is_booked' => in_array($timeSlot, $bookedSlots, true),
                ];
                $currentTime->addMinutes($slotDuration);
            }
        }

        usort($timeSlots, fn ($a, $b) => strcmp($a['time'], $b['time']));

        return response()->json([
            'availabilities' => $timeSlots,
            'is_paused' => false,
        ]);
    }

    public function ticketAvailability(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
        ]);

        $complex = Complex::where('id', $request->complex_id)->where('is_active', true)->first();
        if (!$complex || !$complex->usesTicketBooking()) {
            return response()->json([
                'tickets' => [],
            ]);
        }

        $config = DoctorTicketConfig::where('doctor_id', $request->doctor_id)
            ->where('complex_id', $request->complex_id)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            return response()->json([
                'tickets' => [],
            ]);
        }

        $date = Carbon::parse($request->date, 'Africa/Khartoum')->toDateString();

        if (DoctorPausedDate::where('doctor_id', $request->doctor_id)->where('date', $date)->exists()) {
            return response()->json([
                'tickets' => [],
                'is_paused' => true,
            ]);
        }

        $usedTickets = Reservation::where('doctor_id', $request->doctor_id)
            ->where('complex_id', $request->complex_id)
            ->where('date', $date)
            ->whereNotNull('ticket_number')
            ->where('ticket_number', '>=', $config->from_ticket)
            ->where('ticket_number', '<=', $config->to_ticket)
            ->whereIn('status', ['pending', 'confirmed'])
            ->pluck('ticket_number')
            ->toArray();

        $tickets = [];
        for ($n = $config->from_ticket; $n <= $config->to_ticket; $n++) {
            $tickets[] = [
                'ticket_number' => $n,
                'is_booked' => in_array($n, $usedTickets, true),
            ];
        }

        return response()->json([
            'tickets' => $tickets,
            'is_paused' => false,
        ]);
    }

    public function index(Request $request)
    {
        $query = Reservation::with(['user', 'doctor', 'complex']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        if ($request->has('complex_id')) {
            $query->where('complex_id', $request->complex_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $reservations = $query->orderBy('created_at', 'desc')
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

    public function show($id)
    {
        $reservation = Reservation::with(['user', 'doctor', 'complex'])->findOrFail($id);

        return response()->json([
            'reservation' => $reservation,
        ]);
    }

    public function store(Request $request)
    {
        $complex = Complex::where('id', $request->input('complex_id'))
            ->where('is_active', true)
            ->first();
        if (!$complex) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['complex_id' => ['The selected complex is invalid or not available.']],
            ], 422);
        }

        $rules = [
            'user_id' => 'nullable|exists:users,id',
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
            'patient_name' => 'required|string|max:255',
            'patient_phone' => ['required', 'string', new SudanPhoneNumber()],
        ];

        if ($complex->usesSlotBooking()) {
            $rules['time'] = 'required|date_format:H:i';
        }
        if ($complex->usesTicketBooking()) {
            $rules['ticket_number'] = 'nullable|integer|min:1';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $targetUser = null;
        if ($request->filled('user_id')) {
            $targetUser = User::find($request->input('user_id'));
        }
        if (!$targetUser) {
            $guestPhone = (string) config('guest.user_phone');
            $guestName = (string) config('guest.user_name', 'Guest');
            $targetUser = User::where('phone', $guestPhone)->first();
            if (!$targetUser) {
                $targetUser = User::create([
                    'name' => $guestName,
                    'phone' => $guestPhone,
                    'password' => Hash::make(Str::random(48)),
                ]);
                $targetUser->forceFill([
                    'status' => 'active',
                    'verified_at' => now(),
                ])->save();
            }
        }

        $data = $request->only([
            'doctor_id', 'complex_id', 'date', 'patient_name', 'patient_phone',
        ]);
        $data['is_for_self'] = false;

        if ($complex->usesSlotBooking()) {
            $data['time'] = $request->input('time');
            $data['ticket_number'] = null;
        } else {
            $data['time'] = null;
            $data['ticket_number'] = $request->has('ticket_number')
                ? (int) $request->input('ticket_number')
                : null;
        }

        try {
            $reservation = app(ReservationService::class)->createReservation($targetUser, $data);
            $reservation->forceFill([
                'created_by_admin_user_id' => $request->user()?->id,
                'created_source' => 'dashboard',
            ])->save();

            return response()->json([
                'message' => 'Reservation created successfully',
                'reservation' => $reservation->load(['user', 'doctor', 'complex']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $reservation = Reservation::findOrFail($id);

        $request->validate([
            'status' => 'required|in:pending,confirmed,cancelled,completed,no_show',
        ]);

        $previousStatus = $reservation->status;
        $reservation->update(['status' => $request->status]);

        if ($request->status === 'cancelled') {
            app(ReservationService::class)->releaseSlotOrTicket($reservation);
        }

        if ($request->status === 'completed') {
            Cache::forget('doctor_' . $reservation->doctor_id);
        }

        // Notify user about the status change
        app(NotificationService::class)->sendReservationStatusUpdate(
            $reservation->load(['user', 'doctor', 'complex']),
            $previousStatus
        );

        return response()->json([
            'message' => 'Reservation status updated successfully',
            'reservation' => $reservation->load(['user', 'doctor', 'complex']),
        ]);
    }

    /**
     * Export reservations as CSV (same filters as index).
     */
    public function export(Request $request): StreamedResponse
    {
        $query = Reservation::with(['doctor', 'complex']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        if ($request->filled('complex_id')) {
            $query->where('complex_id', $request->complex_id);
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

        $reservations = $query->orderBy('date')->orderBy('time')->get();

        $filename = 'reservations-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($reservations) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
            fputcsv($out, [
                'ID',
                'Date',
                'Time',
                'Patient Name',
                'Patient Phone',
                'Status',
                'Doctor',
                'Complex',
                'Created At',
            ]);
            foreach ($reservations as $r) {
                fputcsv($out, [
                    $r->id,
                    $r->date?->format('Y-m-d'),
                    $r->time ? (\Carbon\Carbon::parse($r->time)->format('H:i')) : '',
                    $r->patient_name,
                    $r->patient_phone,
                    $r->status,
                    $r->doctor?->name ?? '',
                    $r->complex?->name ?? '',
                    $r->created_at?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

