<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DoctorWeeklySchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WeeklyScheduleController extends Controller
{
    public function index(Request $request)
    {
        $doctorId = $request->get('doctor_id');
        
        if (!$doctorId) {
            return response()->json([
                'message' => 'Doctor ID is required',
            ], 422);
        }

        $schedules = DoctorWeeklySchedule::where('doctor_id', $doctorId)
            ->with('complex')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        // Group by day of week
        $grouped = [];
        foreach ($schedules as $schedule) {
            $day = $schedule->day_of_week;
            if (!isset($grouped[$day])) {
                $grouped[$day] = [
                    'day_of_week' => $day,
                    'day_name' => $schedule->day_name,
                    'slots' => []
                ];
            }
            // Include complex relationship in slot data
            $slotData = $schedule->toArray();
            if ($schedule->complex) {
                $slotData['complex'] = $schedule->complex->toArray();
            }
            $grouped[$day]['slots'][] = $slotData;
        }

        // Sort by day of week
        ksort($grouped);

        return response()->json([
            'schedules' => array_values($grouped),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'day_of_week' => 'required|integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i',
        ]);
        
        // Always calculate end_time as 15 minutes after start_time
        $startTime = \Carbon\Carbon::createFromFormat('H:i', $request->start_time);
        $endTime = $startTime->copy()->addMinutes(15)->format('H:i');

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check for duplicate time slot (same doctor, day, complex, and start_time)
        $existing = DoctorWeeklySchedule::where('doctor_id', $request->doctor_id)
            ->where('complex_id', $request->complex_id)
            ->where('day_of_week', $request->day_of_week)
            ->where('start_time', $request->start_time)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'start_time' => ['This time slot already exists for this doctor, day, and complex.']
                ],
            ], 422);
        }

        $schedule = DoctorWeeklySchedule::create([
            'doctor_id' => $request->doctor_id,
            'complex_id' => $request->complex_id,
            'day_of_week' => $request->day_of_week,
            'start_time' => $request->start_time,
            'end_time' => $endTime,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Schedule created successfully',
            'schedule' => $schedule->load('complex'),
        ], 201);
    }

    public function bulkStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|exists:doctors,id',
            'complex_id' => 'required|exists:complexes,id',
            'day_of_week' => 'required|integer|min:0|max:6',
            'time_slots' => 'required|array|min:1',
            'time_slots.*.start_time' => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $schedules = [];
        foreach ($request->time_slots as $slot) {
            // Calculate end_time as 15 minutes after start_time
            $startTime = \Carbon\Carbon::createFromFormat('H:i', $slot['start_time']);
            $endTime = $startTime->copy()->addMinutes(15)->format('H:i');

            $schedule = DoctorWeeklySchedule::firstOrCreate(
                [
                    'doctor_id' => $request->doctor_id,
                    'complex_id' => $request->complex_id,
                    'day_of_week' => $request->day_of_week,
                    'start_time' => $slot['start_time'],
                ],
                [
                    'end_time' => $endTime,
                    'is_active' => true
                ]
            );
            $schedules[] = $schedule->load('complex');
        }

        return response()->json([
            'message' => 'Schedules created successfully',
            'schedules' => $schedules,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $schedule = DoctorWeeklySchedule::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'start_time' => 'sometimes|date_format:H:i',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only(['is_active']);
        
        // If start_time is being updated, recalculate end_time
        if ($request->has('start_time')) {
            $startTime = \Carbon\Carbon::createFromFormat('H:i', $request->start_time);
            $updateData['start_time'] = $request->start_time;
            $updateData['end_time'] = $startTime->copy()->addMinutes(15)->format('H:i');
        }

        $schedule->update($updateData);

        return response()->json([
            'message' => 'Schedule updated successfully',
            'schedule' => $schedule->load('complex'),
        ]);
    }

    public function destroy($id)
    {
        $schedule = DoctorWeeklySchedule::findOrFail($id);
        $schedule->delete();

        return response()->json([
            'message' => 'Schedule deleted successfully',
        ]);
    }

    public function toggleDay(Request $request, $doctorId)
    {
        $validator = Validator::make($request->all(), [
            'day_of_week' => 'required|integer|min:0|max:6',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DoctorWeeklySchedule::where('doctor_id', $doctorId)
            ->where('day_of_week', $request->day_of_week)
            ->update(['is_active' => $request->is_active]);

        return response()->json([
            'message' => 'Day schedule updated successfully',
        ]);
    }
}

