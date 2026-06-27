<?php

namespace App\Http\Controllers;

use App\Models\ComplexChip;
use App\Models\Major;
use App\Support\PublicStorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MajorController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 50);
        $page = $request->get('page', 1);

        $cacheKey = 'majors_' . $perPage . '_' . $page;

        $result = Cache::remember($cacheKey, 3600, function () use ($perPage, $page) {
            $majors = Major::paginate($perPage, ['*'], 'page', $page);
            return [
                'majors' => $majors->items(),
                'pagination' => [
                    'current_page' => $majors->currentPage(),
                    'per_page' => $majors->perPage(),
                    'total' => $majors->total(),
                    'last_page' => $majors->lastPage(),
                ],
            ];
        });

        return response()->json($result);
    }

    public function doctors(Request $request, $id)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $complexId = $request->query('complex_id');

        $major = Major::find($id);

        // If id is a chip (e.g. from doctor detail tapping a chip), return doctors who have that chip
        if (! $major) {
            $chip = ComplexChip::with('complex')->find($id);
            if ($chip) {
                return $this->doctorsByChip($request, $chip, $perPage, $page, $complexId);
            }

            return response()->json([
                'message' => 'The requested specialty or assignment was not found.',
                'doctors' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ], 404);
        }

        if ($complexId) {
            $doctorIds = \App\Models\DoctorComplexMajor::where('major_id', $id)
                ->where('complex_id', $complexId)
                ->where('works', true)
                ->pluck('doctor_id')
                ->unique()
                ->values()
                ->all();

            $doctorsQuery = \App\Models\Doctor::where('status', 'active')
                ->with(['complexes' => function ($q) {
                    $q->where('is_active', true);
                }, 'majors']);

            if (count($doctorIds) > 0) {
                $doctors = $doctorsQuery->whereIn('id', $doctorIds)
                    ->orderBy('name')
                    ->paginate($perPage, ['*'], 'page', $page);
            } else {
                $doctors = $doctorsQuery->whereRaw('1 = 0')->paginate($perPage, ['*'], 'page', $page);
            }
        } else {
            $doctors = $major->doctors()
                ->where('status', 'active')
                ->with(['complexes' => function ($q) {
                    $q->where('is_active', true);
                }, 'majors'])
                ->paginate($perPage, ['*'], 'page', $page);
        }

        // Format image URLs to full URLs
        $doctors->getCollection()->transform(function ($doctor) {
            if ($doctor->image) {
                $doctor->image = PublicStorageUrl::resolve($doctor->image);
            }
            return $doctor;
        });

        return response()->json([
            'doctors' => $doctors->items(),
            'pagination' => [
                'current_page' => $doctors->currentPage(),
                'per_page' => $doctors->perPage(),
                'total' => $doctors->total(),
                'last_page' => $doctors->lastPage(),
            ],
        ]);
    }

    /**
     * Return doctors who have this chip (optionally at the given complex).
     */
    private function doctorsByChip(Request $request, ComplexChip $chip, int $perPage, int $page, ?string $complexId)
    {
        $doctorIds = $chip->doctors()->pluck('doctors.id')->toArray();

        if ($complexId) {
            $doctorIdsAtComplex = \App\Models\Doctor::whereHas('complexes', function ($q) use ($complexId) {
                $q->where('complexes.id', $complexId);
            })->pluck('id')->toArray();
            $doctorIds = array_values(array_intersect($doctorIds, $doctorIdsAtComplex));
        }

        $doctorsQuery = \App\Models\Doctor::where('status', 'active')
            ->with(['complexes' => function ($q) {
                $q->where('is_active', true);
            }, 'majors']);

        if ($doctorIds === []) {
            $doctors = $doctorsQuery->whereRaw('1 = 0')->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        } else {
            $doctors = $doctorsQuery->whereIn('id', $doctorIds)->orderBy('name')->paginate($perPage, ['*'], 'page', $page);
        }

        $doctors->getCollection()->transform(function ($doctor) {
            if ($doctor->image) {
                $doctor->image = PublicStorageUrl::resolve($doctor->image);
            }
            return $doctor;
        });

        return response()->json([
            'doctors' => $doctors->items(),
            'pagination' => [
                'current_page' => $doctors->currentPage(),
                'per_page' => $doctors->perPage(),
                'total' => $doctors->total(),
                'last_page' => $doctors->lastPage(),
            ],
        ]);
    }
}

