<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\Reservation;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    public function index(Request $request, $doctorId)
    {
        $doctor = Doctor::where('id', $doctorId)->where('status', 'active')->first();
        if (!$doctor) {
            return response()->json(['message' => 'Doctor not found.'], 404);
        }

        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $reviews = Review::where('doctor_id', $doctorId)
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'reviews' => $reviews->items(),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
            'reservation_id' => 'required|exists:reservations,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $reservation = Reservation::where('id', $request->reservation_id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$reservation) {
            return response()->json([
                'message' => 'Reservation not found or does not belong to you',
            ], 404);
        }

        if ($reservation->status !== 'completed') {
            return response()->json([
                'message' => 'You can only rate a doctor after the reservation is completed',
            ], 403);
        }

        if ((int) $reservation->doctor_id !== (int) $request->doctor_id) {
            return response()->json([
                'message' => 'Reservation does not match the selected doctor',
            ], 422);
        }

        $existingReview = Review::where('user_id', $request->user()->id)
            ->where('doctor_id', $request->doctor_id)
            ->where('reservation_id', $request->reservation_id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'message' => 'You have already reviewed this doctor for this reservation',
            ], 400);
        }

        $review = Review::create([
            'user_id' => $request->user()->id,
            'doctor_id' => $request->doctor_id,
            'reservation_id' => $request->reservation_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
        ]);

        Cache::forget('doctor_' . $request->doctor_id);

        return response()->json([
            'message' => 'Review created successfully',
            'review' => $review->load(['user', 'doctor']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $review = Review::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $review->update($request->only(['rating', 'comment']));

        return response()->json([
            'message' => 'Review updated successfully',
            'review' => $review->load(['user', 'doctor']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $review = Review::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $review->delete();

        return response()->json([
            'message' => 'Review deleted successfully',
        ]);
    }
}

