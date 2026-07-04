<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Services\DoctorRatingService;
use App\Support\PublicStorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DoctorController extends Controller
{
    public function __construct(
        protected DoctorRatingService $ratingService
    ) {
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $cacheKey = 'doctors_search_v6_' . md5($query . $perPage . $page);
        
        $result = Cache::remember($cacheKey, 300, function () use ($query, $perPage, $page) {
            $doctorsQuery = Doctor::active();
            
            if (!empty($query)) {
                $doctorsQuery->where('name', 'like', "%{$query}%");
            }
            
            $doctors = $doctorsQuery->with(['complexes' => function ($q) {
                $q->where('is_active', true);
            }, 'majors'])
                ->paginate($perPage, ['*'], 'page', $page);

            $ratingService = app(DoctorRatingService::class);

            // Build doctors array with image URLs and rating so JSON always includes them
            $doctorsList = $doctors->getCollection()->map(function ($doctor) use ($ratingService) {
                $arr = $doctor->toArray();
                if (!empty($arr['image'])) {
                    $arr['image'] = PublicStorageUrl::resolve($arr['image']);
                }
                $arr['average_rating'] = $ratingService->getCompositeRating($doctor);
                $arr['reviews_count'] = $ratingService->getReviewsCount($doctor);
                return $arr;
            })->values()->all();

            return [
                'doctors' => $doctorsList,
                'pagination' => [
                    'current_page' => $doctors->currentPage(),
                    'per_page' => $doctors->perPage(),
                    'total' => $doctors->total(),
                    'last_page' => $doctors->lastPage(),
                ],
            ];
        });

        return response()->json($result);
    }

    /**
     * Curated featured doctors (selected in dashboard, ordered by featured_sort).
     */
    public function featured(Request $request)
    {
        $cacheKey = 'doctors_featured_curated';

        $doctorsList = Cache::remember($cacheKey, 300, function () {
            $ratingService = app(DoctorRatingService::class);

            return Doctor::active()
                ->featured()
                ->with(['complexes' => function ($q) {
                    $q->where('is_active', true);
                }, 'majors'])
                ->limit(6)
                ->get()
                ->map(function ($doctor) use ($ratingService) {
                    $arr = $doctor->toArray();
                    if (! empty($arr['image'])) {
                        $arr['image'] = PublicStorageUrl::resolve($arr['image']);
                    }
                    $arr['average_rating'] = $ratingService->getCompositeRating($doctor);
                    $arr['reviews_count'] = $ratingService->getReviewsCount($doctor);

                    return $arr;
                })
                ->values()
                ->all();
        });

        return response()->json(['doctors' => $doctorsList]);
    }

    public function show(Request $request, $id)
    {
        $complexId = $request->query('complex_id');
        $cacheKey = $complexId ? "doctor_{$id}_complex_{$complexId}" : 'doctor_' . $id;

        $doctor = Cache::remember($cacheKey, 3600, function () use ($id, $complexId) {
            $doctor = Doctor::active()
                ->with(['complexes' => function ($q) {
                    $q->where('is_active', true);
                }, 'majors', 'reviews' => function ($query) {
                    $query->with('user')->latest()->limit(5);
                }])
                ->findOrFail($id);

            if ($complexId) {
                $complex = \App\Models\Complex::with(['majors', 'chips'])->find($complexId);
                if ($complex && $doctor->complexes->contains($complex)) {
                    // Prefer chips when complex has chips: "work this" = assigned, "don't work" = not assigned
                    if ($complex->chips->isNotEmpty()) {
                        $doctorChipIds = $doctor->complexChips()
                            ->where('complex_chips.complex_id', $complexId)
                            ->pluck('complex_chips.id')
                            ->toArray();
                        $specialtyAssignments = $complex->chips->map(function ($chip) use ($doctorChipIds) {
                            return [
                                'major_id' => $chip->id,
                                'name' => $chip->label,
                                'works' => in_array($chip->id, $doctorChipIds),
                            ];
                        })->values()->all();
                    } else {
                        $assignments = \App\Models\DoctorComplexMajor::where('doctor_id', $doctor->id)
                            ->where('complex_id', $complexId)
                            ->get()
                            ->keyBy('major_id');
                        $specialtyAssignments = $complex->majors->map(function ($major) use ($assignments) {
                            $assignment = $assignments->get($major->id);
                            return [
                                'major_id' => $major->id,
                                'name' => $major->name,
                                'works' => $assignment ? $assignment->works : false,
                            ];
                        })->values()->all();
                    }
                    $doctor->setAttribute('specialty_assignments', $specialtyAssignments);
                }
            }

            // All chips this doctor has (all complexes) — for doctor detail "Chips" section
            $doctorChips = $doctor->complexChips()->with('complex:id,name')->get();
            $doctor->setAttribute('chips', $doctorChips->map(function ($c) {
                return [
                    'id' => $c->id,
                    'label' => $c->label,
                    'complex_id' => $c->complex_id,
                    'complex_name' => $c->complex?->name,
                ];
            })->values()->all());

            return $doctor;
        });

        // Format image URL to full URL
        if ($doctor->image) {
            $doctor->image = PublicStorageUrl::resolve($doctor->image);
        }

        $averageRating = $this->ratingService->getCompositeRating($doctor);
        $reviewsCount = $this->ratingService->getReviewsCount($doctor);
        $completedReservationsCount = $this->ratingService->getCompletedReservationsCount($doctor);

        return response()->json([
            'doctor' => $doctor,
            'average_rating' => $averageRating,
            'reviews_count' => $reviewsCount,
            'completed_reservations_count' => $completedReservationsCount,
        ]);
    }

    public function complexes($id)
    {
        $doctor = Doctor::active()
            ->with(['complexes' => function ($q) {
                $q->where('is_active', true);
            }])
            ->findOrFail($id);
        $complexes = $doctor->complexes->map(function ($complex) {
            $arr = $complex->toArray();
            if (!empty($arr['logo'])) {
                $arr['logo'] = PublicStorageUrl::resolve($arr['logo']);
            }
            return $arr;
        })->values();

        return response()->json([
            'complexes' => $complexes,
        ]);
    }

    public function availableDays(Request $request, $id)
    {
        $request->validate([
            'complex_id' => 'required|exists:complexes,id',
        ]);

        $complexId = $request->complex_id;

        // Get all days of week that have active schedules for this doctor and complex
        $availableDays = \App\Models\DoctorWeeklySchedule::where('doctor_id', $id)
            ->where('complex_id', $complexId)
            ->where('is_active', true)
            ->distinct()
            ->pluck('day_of_week')
            ->toArray();

        return response()->json([
            'available_days' => $availableDays, // Array of day numbers: [0, 1, 2, ...] where 0=Sunday, 6=Saturday
        ]);
    }

    public function availability(Request $request, $id)
    {
        $request->validate([
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
        ]);

        $doctor = Doctor::active()
            ->with(['complexes' => function ($q) {
                $q->where('is_active', true);
            }, 'majors'])
            ->findOrFail($id);
        $complexId = $request->complex_id;
        $date = \Carbon\Carbon::parse($request->date, 'Africa/Khartoum');
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 6 = Saturday

        // Get weekly schedule for this day
        $weeklySchedules = \App\Models\DoctorWeeklySchedule::where('doctor_id', $id)
            ->where('complex_id', $complexId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get();

        // Get booked time slots for this date
        $bookedSlots = \App\Models\DoctorAvailability::where('doctor_id', $id)
            ->where('complex_id', $complexId)
            ->where('date', $date->toDateString())
            ->where('is_booked', true)
            ->pluck('start_time')
            ->map(function ($time) {
                return \Carbon\Carbon::parse($time)->format('H:i');
            })
            ->toArray();

        // Generate individual time slots from weekly schedules
        $timeSlots = [];
        $slotDuration = 15; // 15 minutes per slot (can be made configurable)

        foreach ($weeklySchedules as $schedule) {
            $startTime = \Carbon\Carbon::parse($schedule->start_time);
            $endTime = \Carbon\Carbon::parse($schedule->end_time);

            $currentTime = $startTime->copy();
            while ($currentTime->lt($endTime)) {
                $timeSlot = $currentTime->format('H:i');
                
                // Only add if not booked
                if (!in_array($timeSlot, $bookedSlots)) {
                    $timeSlots[] = [
                        'time' => $timeSlot,
                        'is_booked' => false,
                    ];
                } else {
                    $timeSlots[] = [
                        'time' => $timeSlot,
                        'is_booked' => true,
                    ];
                }

                $currentTime->addMinutes($slotDuration);
            }
        }

        // Sort by time
        usort($timeSlots, function ($a, $b) {
            return strcmp($a['time'], $b['time']);
        });

        return response()->json([
            'availabilities' => $timeSlots,
        ]);
    }

    /**
     * Get ticket availability for a date (complexes using ticket booking).
     * Returns tickets in config range with is_booked from existing reservations.
     */
    public function ticketAvailability(Request $request, $id)
    {
        $request->validate([
            'complex_id' => 'required|exists:complexes,id',
            'date' => 'required|date',
        ]);

        $doctor = Doctor::active()->findOrFail($id);
        $complexId = $request->complex_id;
        $complex = \App\Models\Complex::where('id', $complexId)->where('is_active', true)->first();
        if (!$complex || $complex->booking_method !== 'tickets') {
            return response()->json([
                'tickets' => [],
            ]);
        }

        $config = \App\Models\DoctorTicketConfig::where('doctor_id', $id)
            ->where('complex_id', $complexId)
            ->where('is_active', true)
            ->first();

        if (!$config) {
            return response()->json([
                'tickets' => [],
            ]);
        }

        $date = \Carbon\Carbon::parse($request->date, 'Africa/Khartoum')->toDateString();
        $usedTickets = \App\Models\Reservation::where('doctor_id', $id)
            ->where('complex_id', $complexId)
            ->where('date', $date)
            ->whereNotNull('ticket_number')
            ->where('ticket_number', '>=', $config->from_ticket)
            ->where('ticket_number', '<=', $config->to_ticket)
            ->whereIn('status', ['pending', 'confirmed'])
            ->pluck('ticket_number')
            ->toArray();

        $tickets = [];
        for ($n = $config->from_ticket; $n <= $config->to_ticket; $n++) {
            $tickets[] = [
                'ticket_number' => $n,
                'is_booked' => in_array($n, $usedTickets, true),
            ];
        }

        return response()->json([
            'tickets' => $tickets,
        ]);
    }
}

