<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\UserFavorite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FavoriteController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $favorites = UserFavorite::where('user_id', $request->user()->id)
            ->whereHas('doctor', fn ($q) => $q->where('status', 'active'))
            ->with(['doctor' => function ($query) {
                $query->where('status', 'active')
                    ->with(['complexes' => function ($q) {
                        $q->where('is_active', true);
                    }, 'majors', 'reviews']);
            }])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Add average rating to each doctor
        $favorites->getCollection()->transform(function ($favorite) {
            if ($favorite->doctor) {
                $favorite->doctor->average_rating = round($favorite->doctor->reviews()->avg('rating') ?? 0, 2);
                $favorite->doctor->reviews_count = $favorite->doctor->reviews()->count();
            }
            return $favorite;
        });

        return response()->json([
            'favorites' => $favorites->items(),
            'pagination' => [
                'current_page' => $favorites->currentPage(),
                'per_page' => $favorites->perPage(),
                'total' => $favorites->total(),
                'last_page' => $favorites->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $doctor = Doctor::where('id', $request->doctor_id)->where('status', 'active')->first();
        if (!$doctor) {
            return response()->json([
                'message' => 'This doctor is not available for favorites.',
            ], 422);
        }

        // Check if already favorited
        $existing = UserFavorite::where('user_id', $request->user()->id)
            ->where('doctor_id', $request->doctor_id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Doctor is already in favorites',
                'favorite' => $existing->load(['doctor']),
            ]);
        }

        $favorite = UserFavorite::create([
            'user_id' => $request->user()->id,
            'doctor_id' => $request->doctor_id,
        ]);

        return response()->json([
            'message' => 'Doctor added to favorites',
            'favorite' => $favorite->load(['doctor' => function ($query) {
                $query->where('status', 'active')
                    ->with(['complexes' => function ($q) {
                        $q->where('is_active', true);
                    }, 'majors', 'reviews']);
            }]),
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $favorite = UserFavorite::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $favorite->delete();

        return response()->json([
            'message' => 'Doctor removed from favorites',
        ]);
    }

    public function toggle(Request $request, $doctorId)
    {
        $doctor = Doctor::where('id', $doctorId)->where('status', 'active')->first();
        if (!$doctor) {
            return response()->json([
                'message' => 'This doctor is not available for favorites.',
            ], 422);
        }

        $favorite = UserFavorite::where('user_id', $request->user()->id)
            ->where('doctor_id', $doctorId)
            ->first();

        if ($favorite) {
            $favorite->delete();
            return response()->json([
                'message' => 'Doctor removed from favorites',
                'is_favorite' => false,
            ]);
        } else {
            $favorite = UserFavorite::create([
                'user_id' => $request->user()->id,
                'doctor_id' => $doctorId,
            ]);
            return response()->json([
                'message' => 'Doctor added to favorites',
                'is_favorite' => true,
                'favorite' => $favorite->load(['doctor' => function ($query) {
                    $query->where('status', 'active')
                        ->with(['complexes' => function ($q) {
                            $q->where('is_active', true);
                        }, 'majors']);
                }]),
            ]);
        }
    }
}

