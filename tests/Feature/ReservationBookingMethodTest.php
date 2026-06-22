<?php

namespace Tests\Feature;

use App\Models\Complex;
use App\Models\Doctor;
use App\Models\DoctorTicketConfig;
use App\Models\DoctorWeeklySchedule;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests for slot vs ticket booking methods.
 * Uses MySQL test database (see phpunit.xml: DB_DATABASE=pocket_test).
 * Create the database once: e.g. CREATE DATABASE pocket_test;
 */
class ReservationBookingMethodTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Doctor $doctor;
    protected Complex $complexSlots;
    protected Complex $complexTickets;

    protected function createUser(array $overrides = []): User
    {
        static $phoneSeq = 0;
        return User::create(array_merge([
            'name' => 'Test User',
            'phone' => '0900000' . str_pad((string) (++$phoneSeq), 4, '0', STR_PAD_LEFT),
            'password' => Hash::make('password'),
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
        $this->doctor = Doctor::factory()->create(['status' => 'active']);
        $this->complexSlots = Complex::factory()->create(['booking_method' => 'slots']);
        $this->complexTickets = Complex::factory()->create(['booking_method' => 'tickets']);
        $this->doctor->complexes()->attach([$this->complexSlots->id, $this->complexTickets->id]);
    }

    public function test_slot_booking_requires_time(): void
    {
        $this->complexSlots->doctors()->syncWithoutDetaching([$this->doctor->id]);
        DoctorWeeklySchedule::create([
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexSlots->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_active' => true,
        ]);

        $service = app(ReservationService::class);
        $date = now()->addDay()->format('Y-m-d');

        $reservation = $service->createReservation($this->user, [
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexSlots->id,
            'date' => $date,
            'time' => '10:00',
            'patient_name' => $this->user->name,
            'patient_phone' => $this->user->phone,
            'is_for_self' => true,
        ]);

        $this->assertInstanceOf(Reservation::class, $reservation);
        $this->assertNull($reservation->ticket_number);
        $this->assertSame('10:00', $reservation->time?->format('H:i'));
        $this->assertSame($date, $reservation->date->format('Y-m-d'));
    }

    public function test_ticket_booking_allocates_number_in_range(): void
    {
        DoctorTicketConfig::create([
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexTickets->id,
            'from_ticket' => 5,
            'to_ticket' => 20,
            'is_active' => true,
        ]);

        $service = app(ReservationService::class);
        $date = now()->addDay()->format('Y-m-d');

        $reservation = $service->createReservation($this->user, [
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexTickets->id,
            'date' => $date,
            'patient_name' => $this->user->name,
            'patient_phone' => $this->user->phone,
            'is_for_self' => true,
        ]);

        $this->assertInstanceOf(Reservation::class, $reservation);
        $this->assertNotNull($reservation->ticket_number);
        $this->assertGreaterThanOrEqual(5, $reservation->ticket_number);
        $this->assertLessThanOrEqual(20, $reservation->ticket_number);
        $this->assertNull($reservation->time);
        $this->assertSame($date, $reservation->date->format('Y-m-d'));
    }

    public function test_ticket_booking_sequential_numbers(): void
    {
        DoctorTicketConfig::create([
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexTickets->id,
            'from_ticket' => 1,
            'to_ticket' => 3,
            'is_active' => true,
        ]);

        $service = app(ReservationService::class);
        $date = now()->addDay()->format('Y-m-d');
        $payload = [
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexTickets->id,
            'date' => $date,
            'patient_name' => $this->user->name,
            'patient_phone' => $this->user->phone,
            'is_for_self' => true,
        ];

        $r1 = $service->createReservation($this->user, $payload);
        $user2 = $this->createUser();
        $payload['patient_name'] = $user2->name;
        $payload['patient_phone'] = $user2->phone;
        $r2 = $service->createReservation($user2, $payload);
        $user3 = $this->createUser();
        $payload['patient_name'] = $user3->name;
        $payload['patient_phone'] = $user3->phone;
        $r3 = $service->createReservation($user3, $payload);

        $numbers = [$r1->ticket_number, $r2->ticket_number, $r3->ticket_number];
        $this->assertEqualsCanonicalizing([1, 2, 3], $numbers);
    }

    public function test_ticket_booking_throws_when_range_exhausted(): void
    {
        DoctorTicketConfig::create([
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexTickets->id,
            'from_ticket' => 1,
            'to_ticket' => 1,
            'is_active' => true,
        ]);

        $service = app(ReservationService::class);
        $date = now()->addDay()->format('Y-m-d');
        $payload = [
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexTickets->id,
            'date' => $date,
            'patient_name' => $this->user->name,
            'patient_phone' => $this->user->phone,
            'is_for_self' => true,
        ];

        $service->createReservation($this->user, $payload);

        $user2 = $this->createUser();
        $payload['patient_name'] = $user2->name;
        $payload['patient_phone'] = $user2->phone;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No more online tickets');
        $service->createReservation($user2, $payload);
    }

    public function test_ticket_booking_throws_when_no_config(): void
    {
        $service = app(ReservationService::class);
        $date = now()->addDay()->format('Y-m-d');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not configured');
        $service->createReservation($this->user, [
            'doctor_id' => $this->doctor->id,
            'complex_id' => $this->complexTickets->id,
            'date' => $date,
            'patient_name' => $this->user->name,
            'patient_phone' => $this->user->phone,
            'is_for_self' => true,
        ]);
    }
}
