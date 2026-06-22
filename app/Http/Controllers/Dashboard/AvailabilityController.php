<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoctorAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AvailabilityController extends Controller
{
    public function index(Request $request)
    {
        $query = DoctorAvailability::with([
            'doctor' => function ($q) {
                $q->with(['complexes', 'majors']);
            },
            'complex'
        ]);

        if ($request->has('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        if ($request->has('complex_id')) {
            $query->where('complex_id', $request->complex_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        $perPage = $request->get('per_page', 50);
        $page = $request->get('page', 1);

        $availabilities = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'availabilities' => $availabilities->items(),
            'pagination' => [
                'current_page' => $availabilities->currentPage(),
                'per_page' => $availabilities->perPage(),
                'total' => $availabilities->total(),
                'last_page' => $availabilities->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $availability = DoctorAvailability::create([
            'doctor_id' => $request->doctor_id,
            'complex_id' => $request->complex_id,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'is_booked' => false,
        ]);

        return response()->json([
            'message' => 'Availability created successfully',
            'availability' => $availability->load(['doctor', 'complex']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $availability = DoctorAvailability::with([
            'doctor' => function ($q) {
                $q->with(['complexes', 'majors']);
            },
            'complex'
        ])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'date' => 'sometimes|date',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'is_booked' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $availability->update($request->only(['date', 'start_time', 'end_time', 'is_booked']));

        return response()->json([
            'message' => 'Availability updated successfully',
            'availability' => $availability->load([
                'doctor' => function ($q) {
                    $q->with(['complexes', 'majors']);
                },
                'complex'
            ]),
        ]);
    }

    public function destroy($id)
    {
        $availability = DoctorAvailability::with(['doctor', 'complex'])->findOrFail($id);
        $availability->delete();

        return response()->json([
            'message' => 'Availability deleted successfully',
        ]);
    }

    public function bulkCreate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
            'time_slots' => 'required|array',
            'time_slots.*.start_time' => 'required|date_format:H:i',
            'time_slots.*.end_time' => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $availabilities = [];
        foreach ($request->time_slots as $slot) {
            $availability = DoctorAvailability::create([
                'doctor_id' => $request->doctor_id,
                'complex_id' => $request->complex_id,
                'date' => $request->date,
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'is_booked' => false,
            ]);
            $availabilities[] = $availability->load([
                'doctor' => function ($q) {
                    $q->with(['complexes', 'majors']);
                },
                'complex'
            ]);
        }

        return response()->json([
            'message' => 'Availabilities created successfully',
            'availabilities' => $availabilities,
        ], 201);
    }

    public function bulkCreateRange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'time_slots' => 'required|array',
            'time_slots.*.start_time' => 'required|date_format:H:i',
            'time_slots.*.end_time' => 'required|date_format:H:i',
            'days_of_week' => 'nullable|array', // [0,1,2,3,4,5,6] for Sun-Sat
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $startDate = \Carbon\Carbon::parse($request->start_date, 'Africa/Khartoum');
        $endDate = \Carbon\Carbon::parse($request->end_date, 'Africa/Khartoum');
        $daysOfWeek = $request->days_of_week ?? [0, 1, 2, 3, 4, 5, 6];

        $availabilities = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            if (in_array($currentDate->dayOfWeek, $daysOfWeek)) {
                foreach ($request->time_slots as $slot) {
                    $availability = DoctorAvailability::create([
                        'doctor_id' => $request->doctor_id,
                        'complex_id' => $request->complex_id,
                        'date' => $currentDate->toDateString(),
                        'start_time' => $slot['start_time'],
                        'end_time' => $slot['end_time'],
                        'is_booked' => false,
                    ]);
                    $availabilities[] = $availability;
                }
            }
            $currentDate->addDay();
        }

        return response()->json([
            'message' => 'Availabilities created successfully',
            'count' => count($availabilities),
            'availabilities' => $availabilities,
        ], 201);
    }
}

