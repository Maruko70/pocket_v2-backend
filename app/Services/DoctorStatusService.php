<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\DoctorStatusHistory;
use Carbon\Carbon;

class DoctorStatusService
{
    public function checkAndUpdateStatuses()
    {
        $now = Carbon::now('Africa/Khartoum');

        // Get all active status history records that need to be checked
        $statusHistories = DoctorStatusHistory::where('is_auto_reactivate', true)
            ->where('to_date', '<=', $now)
            ->whereNull('processed_at')
            ->get();

        foreach ($statusHistories as $history) {
            $doctor = Doctor::find($history->doctor_id);
            if ($doctor) {
                // Reactivate doctor
                $doctor->update(['status' => 'active']);

                // Mark history as processed
                $history->update(['processed_at' => $now]);
            }
        }

        // Check for doctors that should be suspended/disabled based on from_date
        $upcomingStatuses = DoctorStatusHistory::where('from_date', '<=', $now)
            ->where('to_date', '>', $now)
            ->orWhere(function ($query) use ($now) {
                $query->where('from_date', '<=', $now)
                    ->whereNull('to_date');
            })
            ->whereNull('processed_at')
            ->get();

        foreach ($upcomingStatuses as $history) {
            $doctor = Doctor::find($history->doctor_id);
            if ($doctor && $doctor->status !== $history->status) {
                $doctor->update(['status' => $history->status]);
                // Do not set processed_at here; only set it when reactivating at to_date (first loop)
            }
        }
    }

    public function setDoctorStatus(Doctor $doctor, string $status, ?Carbon $fromDate = null, ?Carbon $toDate = null, bool $autoReactivate = false)
    {
        $fromDate = $fromDate ?? Carbon::now('Africa/Khartoum');

        // Create status history record
        DoctorStatusHistory::create([
            'doctor_id' => $doctor->id,
            'status' => $status,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'is_auto_reactivate' => $autoReactivate,
        ]);

        // If from_date is now or in the past, update immediately
        if ($fromDate <= Carbon::now('Africa/Khartoum')) {
            $doctor->update(['status' => $status]);
        }
    }
}

