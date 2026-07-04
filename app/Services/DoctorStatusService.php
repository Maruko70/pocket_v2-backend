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

        // Check for doctors whose scheduled status change window has started.
        // Only the latest unprocessed record per doctor is applied so an older
        // record can never override a newer admin decision.
        $upcomingStatuses = DoctorStatusHistory::where(function ($query) use ($now) {
            $query->where(function ($q) use ($now) {
                $q->where('from_date', '<=', $now)
                    ->where('to_date', '>', $now);
            })->orWhere(function ($q) use ($now) {
                $q->where('from_date', '<=', $now)
                    ->whereNull('to_date');
            });
        })
            ->whereNull('processed_at')
            ->orderBy('id')
            ->get()
            ->groupBy('doctor_id')
            ->map(fn ($records) => $records->last());

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
        $now = Carbon::now('Africa/Khartoum');

        // Close out any pending history records so a stale record (e.g. an
        // indefinite "unavailable") cannot be re-applied by the scheduler
        // after the admin explicitly sets a new status.
        if ($fromDate <= $now) {
            DoctorStatusHistory::where('doctor_id', $doctor->id)
                ->whereNull('processed_at')
                ->update(['processed_at' => $now]);
        }

        // Create status history record
        DoctorStatusHistory::create([
            'doctor_id' => $doctor->id,
            'status' => $status,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'is_auto_reactivate' => $autoReactivate,
        ]);

        // If from_date is now or in the past, update immediately
        if ($fromDate <= $now) {
            $doctor->update(['status' => $status]);
        }
    }
}

