<?php

namespace App\Http\Controllers;

use App\Models\Complex;
use App\Models\Reservation;
use App\Models\Review;
use App\Rules\SudanPhoneNumber;
use App\Services\ReservationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReservationController extends Controller
{
    protected $reservationService;

    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $status = $request->get('status');
        $upcoming = $request->get('upcoming'); // true/false for upcoming vs past
        $fromDate = $request->get('from_date');
        $toDate = $request->get('to_date');

        $query = Reservation::where('user_id', $request->user()->id)
            ->with(['doctor', 'complex']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($fromDate) {
            $query->whereDate('date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('date', '<=', $toDate);
        }

        if ($upcoming !== null) {
            $now = now();
            if ($upcoming) {
                $query->where(function ($q) use ($now) {
                    $q->where('date', '>', $now->toDateString())
                      ->orWhere(function ($q2) use ($now) {
                          $q2->where('date', '=', $now->toDateString())
                             ->where(function ($q3) use ($now) {
                                 $q3->whereNull('time')
                                    ->orWhere('time', '>', $now->toTimeString());
                             });
                      });
                });
            } else {
                $query->where(function ($q) use ($now) {
                    $q->where('date', '<', $now->toDateString())
                      ->orWhere(function ($q2) use ($now) {
                          $q2->where('date', '=', $now->toDateString())
                             ->whereNotNull('time')
                             ->where('time', '<=', $now->toTimeString());
                      });
                });
            }
        }

        $reservations = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = $reservations->items();
        $reservationIds = collect($items)->pluck('id')->toArray();
        $userReviewRatings = [];
        if (!empty($reservationIds)) {
            $userReviewRatings = Review::where('user_id', $request->user()->id)
                ->whereIn('reservation_id', $reservationIds)
                ->get()
                ->keyBy('reservation_id')
                ->map(fn ($r) => $r->rating)
                ->toArray();
        }

        $reservationsList = collect($items)->map(function ($reservation) use ($userReviewRatings) {
            $arr = $reservation->toArray();
            if ($reservation->status === 'completed' && isset($userReviewRatings[$reservation->id])) {
                $arr['existing_rating'] = $userReviewRatings[$reservation->id];
            }
            return $arr;
        })->values()->all();

        return response()->json([
            'reservations' => $reservationsList,
            'pagination' => [
                'current_page' => $reservations->currentPage(),
                'per_page' => $reservations->perPage(),
                'total' => $reservations->total(),
                'last_page' => $reservations->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $complex = Complex::where('id', $request->input('complex_id'))->where('is_active', true)->first();
        if (!$complex) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['complex_id' => ['The selected complex is invalid or not available.']],
            ], 422);
        }

        $rules = [
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
            'patient_name' => 'required|string|max:255',
            'patient_phone' => ['required', 'string', new SudanPhoneNumber()],
            'is_for_self' => 'required|boolean',
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

        $data = $request->only([
            'doctor_id', 'complex_id', 'date', 'patient_name', 'patient_phone', 'is_for_self',
        ]);
        if ($complex->usesSlotBooking()) {
            $data['time'] = $request->input('time');
            $data['ticket_number'] = null;
        } else {
            $data['time'] = null;
            $data['ticket_number'] = $request->has('ticket_number') ? (int) $request->input('ticket_number') : null;
        }

        try {
            $reservation = $this->reservationService->createReservation(
                $request->user(),
                $data
            );

            return response()->json([
                'message' => 'Reservation created successfully',
                'reservation' => $reservation->load(['doctor', 'complex']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(Request $request, $id)
    {
        $reservation = Reservation::where('user_id', $request->user()->id)
            ->with(['doctor', 'complex'])
            ->findOrFail($id);

        return response()->json([
            'reservation' => $reservation,
        ]);
    }

    public function canReview(Request $request, $id)
    {
        $reservation = Reservation::where('user_id', $request->user()->id)
            ->find($id);

        if (!$reservation) {
            return response()->json([
                'can_review' => false,
                'already_reviewed' => false,
            ]);
        }

        $canReview = $reservation->status === 'completed';
        $review = Review::where('user_id', $request->user()->id)
            ->where('doctor_id', $reservation->doctor_id)
            ->where('reservation_id', $reservation->id)
            ->first();
        $alreadyReviewed = $review !== null;

        $payload = [
            'can_review' => $canReview && !$alreadyReviewed,
            'already_reviewed' => $alreadyReviewed,
        ];
        if ($alreadyReviewed && $review) {
            $payload['existing_rating'] = $review->rating;
        }

        return response()->json($payload);
    }

    public function destroy(Request $request, $id)
    {
        $reservation = Reservation::where('user_id', $request->user()->id)
            ->findOrFail($id);

        if ($reservation->status === 'cancelled') {
            return response()->json([
                'message' => 'Reservation is already cancelled',
            ], 400);
        }

        $previousStatus = $reservation->status;
        $reservation->update(['status' => 'cancelled']);

        $this->reservationService->releaseSlotOrTicket($reservation);

        app(NotificationService::class)->sendReservationStatusUpdate(
            $reservation->load(['user', 'doctor', 'complex']),
            $previousStatus
        );

        return response()->json([
            'message' => 'Reservation cancelled successfully',
        ]);
    }
}

