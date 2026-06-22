<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MarkNoShowReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function handle(): void
    {
        $hours = config('reservations.auto_no_show_hours');

        if ($hours === null || $hours <= 0) {
            return;
        }

        $cutoff = Carbon::now('Africa/Khartoum')->subHours($hours);

        $reservations = Reservation::with(['user', 'doctor', 'complex'])
            ->where('status', 'confirmed')
            ->whereNull('ticket_number')
            ->where(function ($query) use ($cutoff) {
                $query->whereDate('date', '<', $cutoff->toDateString())
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereDate('date', '=', $cutoff->toDateString())
                            ->whereTime('time', '<=', $cutoff->toTimeString());
                    });
            })
            ->get();

        foreach ($reservations as $reservation) {
            $previousStatus = $reservation->status;
            $reservation->update(['status' => 'no_show']);
            $this->notificationService->sendReservationStatusUpdate($reservation, $previousStatus);
        }
    }
}
