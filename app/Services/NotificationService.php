<?php

namespace App\Services;

use App\Models\DoctorUnavailabilityRequest;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(protected FcmService $fcmService)
    {
    }

    public function sendReservationConfirmation(User $user, Reservation $reservation)
    {
        $title = 'Reservation Confirmed';
        if ($reservation->ticket_number !== null) {
            $message = "Your ticket number is {$reservation->ticket_number} at {$reservation->complex->name} for Dr. {$reservation->doctor->name} on {$reservation->date->format('Y-m-d')}. Your turn will be managed at the complex.";
        } else {
            $message = "Your reservation with Dr. {$reservation->doctor->name} on {$reservation->date->format('Y-m-d')} at " . ($reservation->time ? $reservation->time->format('H:i') : '') . " has been confirmed.";
        }

        // Create notification record
        Notification::create([
            'user_id' => $user->id,
            'type' => 'reservation_confirmation',
            'title' => $title,
            'message' => $message,
            'sent_at' => now(),
        ]);

        // Send SMS if configured
        if (config('services.sms.enabled', false)) {
            $this->sendSMS($user->phone, $message);
        }

        // Push notification (FCM)
        $data = [
            'type' => 'reservation_status',
            'reservation_id' => $reservation->id,
            'new_status' => $reservation->status,
            'doctor_id' => $reservation->doctor_id,
            'date' => $reservation->date?->format('Y-m-d'),
            'time' => $reservation->time?->format('H:i'),
            'ticket_number' => $reservation->ticket_number,
        ];
        $this->fcmService->sendToUserDevices(
            $user->id,
            array_merge($data, [
                'title' => $title,
                'body' => $message,
            ]),
            [
                'title' => $title,
                'body' => $message,
            ]
        );
    }

    public function sendReservationReminder(Reservation $reservation, int $hoursBefore)
    {
        $label = $hoursBefore === 1 ? '1 hour' : "{$hoursBefore} hours";
        $title = 'Appointment Reminder';
        $timePart = $reservation->time ? " at {$reservation->time->format('H:i')}" : '';
        $message = "Reminder: You have an appointment with Dr. {$reservation->doctor->name} in {$label} on {$reservation->date->format('Y-m-d')}{$timePart} at {$reservation->complex->name}.";

        // Create notification record
        Notification::create([
            'user_id' => $reservation->user_id,
            'type' => 'reservation_reminder',
            'title' => $title,
            'message' => $message,
            'sent_at' => now(),
        ]);

        // Send SMS if configured
        if (config('services.sms.enabled', false)) {
            $this->sendSMS($reservation->user->phone, $message);
        }

        // Push notification (FCM)
        $data = [
            'type' => 'reservation_reminder',
            'reservation_id' => $reservation->id,
            'doctor_id' => $reservation->doctor_id,
            'date' => $reservation->date?->format('Y-m-d'),
            'time' => $reservation->time?->format('H:i'),
            'offset_hours' => $hoursBefore,
        ];

        $this->fcmService->sendToUserDevices(
            $reservation->user_id,
            array_merge($data, [
                'title' => $title,
                'body' => $message,
            ]),
            [
                'title' => $title,
                'body' => $message,
            ]
        );
    }

    public function sendReservationStatusUpdate(Reservation $reservation, ?string $previousStatus = null, ?string $reason = null): void
    {
        $user = $reservation->user;
        if (!$user) {
            return;
        }

        $newStatus = $reservation->status;

        $title = 'Reservation Update';
        $timePart = $reservation->time ? " at {$reservation->time->format('H:i')}" : '';
        $message = match ($newStatus) {
            'confirmed' => $reservation->ticket_number !== null
                ? "Your ticket #{$reservation->ticket_number} with Dr. {$reservation->doctor->name} has been confirmed for {$reservation->date->format('Y-m-d')}."
                : "Your reservation with Dr. {$reservation->doctor->name} has been confirmed for {$reservation->date->format('Y-m-d')}{$timePart}.",
            'cancelled' => "Your reservation with Dr. {$reservation->doctor->name} has been cancelled.",
            'completed' => "Your reservation with Dr. {$reservation->doctor->name} has been marked as completed.",
            'no_show' => "You missed your reservation with Dr. {$reservation->doctor->name}.",
            default => "The status of your reservation with Dr. {$reservation->doctor->name} has been updated.",
        };

        if ($reason === 'doctor_unavailable') {
            $message = "Your reservation with Dr. {$reservation->doctor->name} has been cancelled because the doctor became unavailable. Please book another time or choose a different doctor.";
        }

        Notification::create([
            'user_id' => $user->id,
            'type' => 'reservation_status',
            'title' => $title,
            'message' => $message,
            'sent_at' => now(),
        ]);

        if (config('services.sms.enabled', false)) {
            $this->sendSMS($user->phone, $message);
        }

        $data = [
            'type' => $reason === 'doctor_unavailable' ? 'doctor_unavailable' : 'reservation_status',
            'reservation_id' => $reservation->id,
            'new_status' => $newStatus,
            'previous_status' => $previousStatus,
            'doctor_id' => $reservation->doctor_id,
            'date' => $reservation->date?->format('Y-m-d'),
            'time' => $reservation->time?->format('H:i'),
        ];
        if ($reason) {
            $data['reason'] = $reason;
        }

        $this->fcmService->sendToUserDevices(
            $user->id,
            array_merge($data, [
                'title' => $title,
                'body' => $message,
            ]),
            [
                'title' => $title,
                'body' => $message,
            ]
        );
    }

    public function sendUnavailabilityRequestUpdate(DoctorUnavailabilityRequest $request, string $status): void
    {
        $doctor = $request->doctor;
        if (!$doctor) {
            return;
        }

        if ($status === 'approved') {
            $title = 'Unavailability Request Approved';
            $message = 'Your request to mark yourself as unavailable has been approved. You are now unavailable for new bookings.';
        } else {
            $title = 'Unavailability Request Rejected';
            $message = 'Your request to mark yourself as unavailable has been rejected.';
            if ($request->rejection_reason) {
                $message .= ' Reason: ' . $request->rejection_reason;
            }
        }

        $data = [
            'type' => 'unavailability_request_status',
            'request_id' => $request->id,
            'status' => $status,
            'rejection_reason' => $request->rejection_reason,
        ];

        $this->fcmService->sendToDoctorDevices(
            $doctor->id,
            array_merge($data, [
                'title' => $title,
                'body' => $message,
            ]),
            [
                'title' => $title,
                'body' => $message,
            ]
        );
    }

    public function sendSMS(string $phone, string $message)
    {
        $apiKey = config('services.sms.api_key');
        $apiUrl = config('services.sms.api_url');

        if (!$apiKey || !$apiUrl) {
            return;
        }

        try {
            Http::post($apiUrl, [
                'phone' => $phone,
                'message' => $message,
                'api_key' => $apiKey,
            ]);
        } catch (\Exception $e) {
            Log::error('SMS sending failed: ' . $e->getMessage());
        }
    }
}

