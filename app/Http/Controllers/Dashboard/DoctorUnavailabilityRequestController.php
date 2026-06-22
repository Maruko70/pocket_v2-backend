<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoctorUnavailabilityRequest;
use App\Models\Reservation;
use App\Services\DoctorStatusService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DoctorUnavailabilityRequestController extends Controller
{
    public function __construct(
        protected DoctorStatusService $statusService,
        protected NotificationService $notificationService,
    ) {}

    /**
     * List unavailability requests with filters and pagination.
     */
    public function index(Request $request)
    {
        $query = DoctorUnavailabilityRequest::with(['doctor', 'reviewer'])
            ->orderBy('requested_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $requests = $query->paginate($perPage);

        return response()->json([
            'requests' => $requests->items(),
            'pagination' => [
                'total' => $requests->total(),
                'per_page' => $requests->perPage(),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    /**
     * Show a single unavailability request.
     */
    public function show(int $id)
    {
        $request = DoctorUnavailabilityRequest::with(['doctor', 'reviewer'])->findOrFail($id);

        return response()->json(['request' => $request]);
    }

    /**
     * Approve an unavailability request.
     */
    public function approve(int $id)
    {
        $unavailabilityRequest = DoctorUnavailabilityRequest::with('doctor')->findOrFail($id);

        if (!$unavailabilityRequest->isPending()) {
            return response()->json([
                'message' => 'Request has already been processed.',
            ], 422);
        }

        $doctor = $unavailabilityRequest->doctor;
        $fromDate = Carbon::parse($unavailabilityRequest->from_date, 'Africa/Khartoum')->startOfDay();
        $toDate = $unavailabilityRequest->to_date
            ? Carbon::parse($unavailabilityRequest->to_date, 'Africa/Khartoum')->endOfDay()
            : null;

        $this->statusService->setDoctorStatus(
            $doctor,
            'unavailable',
            $fromDate,
            $toDate,
            $unavailabilityRequest->auto_reactivate
        );

        // Cancel affected upcoming reservations and notify patients
        $reservationsQuery = Reservation::with(['user', 'doctor', 'complex'])
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', ['pending', 'confirmed']);

        if ($toDate) {
            $reservationsQuery->whereBetween('date', [
                $fromDate->toDateString(),
                $toDate->toDateString(),
            ]);
        } else {
            $reservationsQuery->whereDate('date', '>=', $fromDate->toDateString());
        }

        $reservations = $reservationsQuery->get();

        foreach ($reservations as $reservation) {
            $previousStatus = $reservation->status;
            $reservation->update(['status' => 'cancelled']);
            $this->notificationService->sendReservationStatusUpdate(
                $reservation,
                $previousStatus,
                'doctor_unavailable'
            );
        }

        $unavailabilityRequest->update([
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
        ]);

        $this->notificationService->sendUnavailabilityRequestUpdate($unavailabilityRequest->fresh(), 'approved');

        return response()->json([
            'message' => 'Request approved successfully',
            'request' => $unavailabilityRequest->fresh(['doctor', 'reviewer']),
        ]);
    }

    /**
     * Reject an unavailability request.
     */
    public function reject(Request $request, int $id)
    {
        $unavailabilityRequest = DoctorUnavailabilityRequest::with('doctor')->findOrFail($id);

        if (!$unavailabilityRequest->isPending()) {
            return response()->json([
                'message' => 'Request has already been processed.',
            ], 422);
        }

        $rejectionReason = $request->input('rejection_reason');

        $unavailabilityRequest->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by' => auth()->id(),
            'rejection_reason' => $rejectionReason,
        ]);

        $this->notificationService->sendUnavailabilityRequestUpdate($unavailabilityRequest->fresh(), 'rejected');

        return response()->json([
            'message' => 'Request rejected successfully',
            'request' => $unavailabilityRequest->fresh(['doctor', 'reviewer']),
        ]);
    }
}
