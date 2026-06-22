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
use Illuminate\Support\Facades\Cache;

class SendReservationRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handle(): void
    {
        $now = Carbon::now('Africa/Khartoum');
        $hoursList = config('notifications.reservation_reminder_hours', [24, 2]);

        foreach ($hoursList as $hours) {
            $targetTime = $now->copy()->addHours((int) $hours);
            $this->sendReminders($targetTime, (int) $hours);
        }
    }

    protected function sendReminders(Carbon $targetTime, int $hoursBefore): void
    {
        $reservations = Reservation::with(['user', 'doctor', 'complex'])
            ->where('status', 'confirmed')
            ->whereNotNull('time')
            ->whereDate('date', $targetTime->toDateString())
            ->whereTime('time', '>=', $targetTime->copy()->subMinutes(5)->toTimeString())
            ->whereTime('time', '<=', $targetTime->copy()->addMinutes(5)->toTimeString())
            ->get();

        foreach ($reservations as $reservation) {
            // Check if reminder already sent for this offset
            $reminderKey = "reminder_{$hoursBefore}h_{$reservation->id}";
            if (!Cache::has($reminderKey)) {
                $this->notificationService->sendReservationReminder($reservation, $hoursBefore);
                Cache::put($reminderKey, true, now()->addDay());
            }
        }
    }
}

