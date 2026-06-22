<?php

namespace App\Services;

use App\Models\Complex;
use App\Models\DoctorAvailability;
use App\Models\DoctorPausedDate;
use App\Models\DoctorTicketConfig;
use App\Models\Reservation;
use App\Models\User;
use App\Rules\SudanPhoneNumber;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function createReservation(User $user, array $data)
    {
        $complex = Complex::findOrFail($data['complex_id']);

        if ($complex->usesTicketBooking()) {
            return $this->createTicketReservation($user, $data);
        }

        return $this->createSlotReservation($user, $data);
    }

    protected function createTicketReservation(User $user, array $data)
    {
        return DB::transaction(function () use ($user, $data) {
            $doctor = \App\Models\Doctor::findOrFail($data['doctor_id']);
            if ($doctor->status !== 'active') {
                throw new \Exception('Doctor is not available for reservations');
            }

            $complex = Complex::findOrFail($data['complex_id']);
            if (!$complex->usesTicketBooking()) {
                throw new \Exception('This complex does not use ticket booking');
            }

            $config = DoctorTicketConfig::where('doctor_id', $data['doctor_id'])
                ->where('complex_id', $data['complex_id'])
                ->where('is_active', true)
                ->first();

            if (!$config) {
                throw new \Exception('Ticket booking is not configured for this doctor at this complex');
            }

            $date = Carbon::parse($data['date'], 'Africa/Khartoum')->toDateString();

            if (DoctorPausedDate::where('doctor_id', $data['doctor_id'])->where('date', $date)->exists()) {
                throw new \Exception('Doctor is not accepting new reservations for this date');
            }

            $usedTickets = Reservation::where('doctor_id', $data['doctor_id'])
                ->where('complex_id', $data['complex_id'])
                ->where('date', $date)
                ->whereNotNull('ticket_number')
                ->where('ticket_number', '>=', $config->from_ticket)
                ->where('ticket_number', '<=', $config->to_ticket)
                ->whereIn('status', ['pending', 'confirmed'])
                ->pluck('ticket_number')
                ->toArray();

            $requestedTicket = isset($data['ticket_number']) ? (int) $data['ticket_number'] : null;
            $nextTicket = null;

            if ($requestedTicket !== null) {
                if ($requestedTicket < $config->from_ticket || $requestedTicket > $config->to_ticket) {
                    throw new \Exception('Selected ticket number is not in the valid range for this complex.');
                }
                if (in_array($requestedTicket, $usedTickets, true)) {
                    throw new \Exception('This ticket number is already booked. Please choose another.');
                }
                $nextTicket = $requestedTicket;
            } else {
                for ($n = $config->from_ticket; $n <= $config->to_ticket; $n++) {
                    if (!in_array($n, $usedTickets, true)) {
                        $nextTicket = $n;
                        break;
                    }
                }
            }

            if ($nextTicket === null) {
                throw new \Exception('No more online tickets available for this doctor on this date. Please visit the complex for manual ticketing.');
            }

            // Prevent duplicate: same doctor, same day, same ticket number, same complex (re-check right before create)
            $ticketAlreadyTaken = Reservation::where('doctor_id', $data['doctor_id'])
                ->where('complex_id', $data['complex_id'])
                ->where('date', $date)
                ->where('ticket_number', $nextTicket)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();
            if ($ticketAlreadyTaken) {
                throw new \Exception('This ticket number is already booked. Please choose another.');
            }

            $patientName = $data['is_for_self'] ? $user->name : $data['patient_name'];
            $patientPhone = $data['is_for_self'] ? $user->phone : SudanPhoneNumber::normalize($data['patient_phone']);

            $reservation = Reservation::create([
                'user_id' => $user->id,
                'doctor_id' => $data['doctor_id'],
                'complex_id' => $data['complex_id'],
                'date' => $date,
                'time' => null,
                'ticket_number' => $nextTicket,
                'patient_name' => $patientName,
                'patient_phone' => $patientPhone,
                'status' => 'pending',
            ]);

            $this->notificationService->sendReservationConfirmation($user, $reservation);

            return $reservation;
        });
    }

    protected function createSlotReservation(User $user, array $data)
    {
        return DB::transaction(function () use ($user, $data) {
            $doctor = \App\Models\Doctor::findOrFail($data['doctor_id']);
            if ($doctor->status !== 'active') {
                throw new \Exception('Doctor is not available for reservations');
            }

            $date = Carbon::parse($data['date'], 'Africa/Khartoum');
            $dateString = $date->toDateString();

            if (DoctorPausedDate::where('doctor_id', $data['doctor_id'])->where('date', $dateString)->exists()) {
                throw new \Exception('Doctor is not accepting new reservations for this date');
            }

            $dayOfWeek = $date->dayOfWeek;
            $timeSlot = $data['time'];

            $weeklySchedule = \App\Models\DoctorWeeklySchedule::where('doctor_id', $data['doctor_id'])
                ->where('complex_id', $data['complex_id'])
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
                ->get();

            if ($weeklySchedule->isEmpty()) {
                throw new \Exception('Doctor is not available on this day');
            }

            $isValidTimeSlot = false;
            foreach ($weeklySchedule as $schedule) {
                $startTime = Carbon::parse($schedule->start_time);
                $endTime = Carbon::parse($schedule->end_time);
                $slotTime = Carbon::parse($timeSlot);

                if ($slotTime->gte($startTime) && $slotTime->lt($endTime)) {
                    $isValidTimeSlot = true;
                    break;
                }
            }

            if (!$isValidTimeSlot) {
                throw new \Exception('Selected time slot is not available');
            }

            $availability = DoctorAvailability::where('doctor_id', $data['doctor_id'])
                ->where('complex_id', $data['complex_id'])
                ->where('date', $data['date'])
                ->where('start_time', $timeSlot)
                ->where('is_booked', true)
                ->first();

            if ($availability) {
                throw new \Exception('This time slot is already booked');
            }

            $availability = DoctorAvailability::firstOrCreate(
                [
                    'doctor_id' => $data['doctor_id'],
                    'complex_id' => $data['complex_id'],
                    'date' => $data['date'],
                    'start_time' => $timeSlot,
                ],
                [
                    'end_time' => Carbon::parse($timeSlot)->addMinutes(15)->format('H:i'),
                    'is_booked' => false,
                ]
            );

            if ($availability->is_booked) {
                throw new \Exception('This time slot is already booked');
            }

            // Prevent duplicate: same doctor, same day, same time, same complex (any non-cancelled reservation)
            $slotAlreadyTaken = Reservation::where('doctor_id', $data['doctor_id'])
                ->where('complex_id', $data['complex_id'])
                ->where('date', $dateString)
                ->where('time', $timeSlot)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();
            if ($slotAlreadyTaken) {
                throw new \Exception('This time slot is already booked.');
            }

            $reservationsCount = Reservation::where('doctor_id', $data['doctor_id'])
                ->where('complex_id', $data['complex_id'])
                ->where('date', $data['date'])
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($reservationsCount >= 10) {
                throw new \Exception('Maximum reservations reached for this doctor on this date');
            }

            $patientName = $data['is_for_self'] ? $user->name : $data['patient_name'];
            $patientPhone = $data['is_for_self'] ? $user->phone : SudanPhoneNumber::normalize($data['patient_phone']);

            $reservation = Reservation::create([
                'user_id' => $user->id,
                'doctor_id' => $data['doctor_id'],
                'complex_id' => $data['complex_id'],
                'date' => $data['date'],
                'time' => $data['time'],
                'patient_name' => $patientName,
                'patient_phone' => $patientPhone,
                'status' => 'pending',
            ]);

            $availability->update(['is_booked' => true]);

            $this->notificationService->sendReservationConfirmation($user, $reservation);

            return $reservation;
        });
    }

    /**
     * When a reservation is cancelled, release the slot (DoctorAvailability.is_booked = false)
     * or free the ticket for reuse. Ticket availability is derived from non-cancelled reservations,
     * so only slot-based reservations need an explicit update.
     */
    public function releaseSlotOrTicket(Reservation $reservation): void
    {
        if ($reservation->isTicketReservation()) {
            // Ticket "slot" is just the reservation row; availability excludes cancelled, so nothing to update.
            return;
        }

        if ($reservation->time === null) {
            return;
        }

        $date = $reservation->date instanceof \Carbon\Carbon
            ? $reservation->date->toDateString()
            : $reservation->date;
        $startTime = $reservation->time instanceof \Carbon\Carbon
            ? $reservation->time->format('H:i')
            : $reservation->time;

        DoctorAvailability::where('doctor_id', $reservation->doctor_id)
            ->where('complex_id', $reservation->complex_id)
            ->where('date', $date)
            ->where('start_time', $startTime)
            ->update(['is_booked' => false]);
    }
}

