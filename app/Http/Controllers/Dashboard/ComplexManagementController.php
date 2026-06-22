<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Complex;
use App\Models\ComplexChip;
use App\Models\DoctorComplexMajor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ComplexManagementController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $search = $request->get('search', $request->get('q'));
        $ids = $request->get('ids');

        $query = Complex::with('doctors');
        $idList = $ids ? (is_array($ids) ? $ids : array_filter(array_map('intval', explode(',', $ids)))) : [];
        $hasSearch = $search && strlen(trim($search)) > 0;

        if ($hasSearch && !empty($idList)) {
            $query->where(function ($q) use ($search, $idList) {
                $q->where('name', 'like', '%' . trim($search) . '%')
                  ->orWhereIn('id', $idList);
            });
        } elseif ($hasSearch) {
            $query->where('name', 'like', '%' . trim($search) . '%');
        } elseif (!empty($idList)) {
            $query->whereIn('id', $idList);
        }
        $complexes = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'complexes' => $complexes->items(),
            'pagination' => [
                'current_page' => $complexes->currentPage(),
                'per_page' => $complexes->perPage(),
                'total' => $complexes->total(),
                'last_page' => $complexes->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string',
            'logo' => 'nullable|image|max:2048',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'booking_method' => 'nullable|in:slots,tickets',
            'is_active' => 'nullable|boolean',
            'major_ids' => 'nullable|array',
            'major_ids.*' => 'exists:majors,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['name', 'bio', 'phone', 'address']);
        $data['booking_method'] = $request->input('booking_method', 'slots');
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('complexes', 'public');
        }

        $complex = Complex::create($data);

        if ($request->has('major_ids')) {
            $complex->majors()->sync($request->major_ids);
        }

        return response()->json([
            'message' => 'Complex created successfully',
            'complex' => $complex->load('majors'),
        ], 201);
    }

    public function show($id)
    {
        $complex = Complex::with(['doctors' => function ($query) use ($id) {
            $query->with(['majors', 'complexes', 'complexChips' => function ($q) use ($id) {
                $q->where('complex_id', $id);
            }]);
        }, 'ticketConfigs.doctor', 'majors', 'chips'])->findOrFail($id);

        return response()->json([
            'complex' => $complex,
        ]);
    }

    public function update(Request $request, $id)
    {
        $complex = Complex::with('doctors')->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'bio' => 'nullable|string',
            'logo' => 'nullable|image|max:2048',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'booking_method' => 'nullable|in:slots,tickets',
            'is_active' => 'nullable|boolean',
            'major_ids' => 'nullable|array',
            'major_ids.*' => 'exists:majors,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['name', 'bio', 'phone', 'address']);
        if ($request->has('booking_method')) {
            $data['booking_method'] = $request->input('booking_method');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        if ($request->hasFile('logo')) {
            if ($complex->logo) {
                Storage::disk('public')->delete($complex->logo);
            }
            $data['logo'] = $request->file('logo')->store('complexes', 'public');
        }

        $complex->update($data);

        if ($request->has('major_ids')) {
            $complex->majors()->sync($request->major_ids);
        }

        return response()->json([
            'message' => 'Complex updated successfully',
            'complex' => $complex->load('majors'),
        ]);
    }

    public function getDoctorSpecialties($complexId, $doctorId)
    {
        $complex = Complex::with('majors')->findOrFail($complexId);
        $complex->doctors()->findOrFail($doctorId);

        $assignments = DoctorComplexMajor::where('complex_id', $complexId)
            ->where('doctor_id', $doctorId)
            ->get()
            ->keyBy('major_id');

        $result = $complex->majors->map(function ($major) use ($assignments) {
            $assignment = $assignments->get($major->id);
            return [
                'major_id' => $major->id,
                'name' => $major->name,
                'works' => $assignment ? $assignment->works : false,
            ];
        })->values();

        return response()->json([
            'specialties' => $result,
        ]);
    }

    public function updateDoctorSpecialties(Request $request, $complexId, $doctorId)
    {
        $complex = Complex::with('majors')->findOrFail($complexId);
        $complex->doctors()->findOrFail($doctorId);

        $validator = Validator::make($request->all(), [
            'assignments' => 'required|array',
            'assignments.*.major_id' => 'required|exists:majors,id',
            'assignments.*.works' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validMajorIds = $complex->majors->pluck('id')->toArray();

        foreach ($request->assignments as $item) {
            if (!in_array($item['major_id'], $validMajorIds)) {
                continue;
            }
            DoctorComplexMajor::updateOrCreate(
                [
                    'doctor_id' => $doctorId,
                    'complex_id' => $complexId,
                    'major_id' => $item['major_id'],
                ],
                ['works' => $item['works']]
            );
        }

        Cache::forget("doctor_{$doctorId}_complex_{$complexId}");
        Cache::forget("doctor_{$doctorId}");

        return $this->getDoctorSpecialties($complexId, $doctorId);
    }

    /**
     * Create a chip for the complex (instant create by label; not linked to majors).
     */
    public function storeChip(Request $request, $complexId)
    {
        $complex = Complex::findOrFail($complexId);

        $validator = Validator::make($request->all(), [
            'label' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $maxOrder = $complex->chips()->max('sort_order') ?? 0;
        $chip = $complex->chips()->create([
            'label' => trim($request->input('label')),
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json([
            'message' => 'Chip added.',
            'chip' => $chip,
        ], 201);
    }

    /**
     * Delete a complex chip.
     */
    public function destroyChip($complexId, $chipId)
    {
        $chip = ComplexChip::where('complex_id', $complexId)->findOrFail($chipId);
        $chip->delete();

        return response()->json([
            'message' => 'Chip removed.',
        ]);
    }

    /**
     * Get which chips a doctor has for this complex.
     */
    public function getDoctorChips($complexId, $doctorId)
    {
        $complex = Complex::with('chips')->findOrFail($complexId);
        $complex->doctors()->findOrFail($doctorId);

        $doctor = $complex->doctors()->where('doctors.id', $doctorId)->first();
        $doctorChipIds = $doctor->complexChips()
            ->where('complex_chips.complex_id', $complexId)
            ->pluck('complex_chips.id')
            ->toArray();

        $chips = $complex->chips->map(function ($chip) use ($doctorChipIds) {
            return [
                'id' => $chip->id,
                'label' => $chip->label,
                'assigned' => in_array($chip->id, $doctorChipIds),
            ];
        });

        return response()->json([
            'chips' => $chips,
        ]);
    }

    /**
     * Update which chips are assigned to a doctor for this complex.
     */
    public function updateDoctorChips(Request $request, $complexId, $doctorId)
    {
        $complex = Complex::with('chips')->findOrFail($complexId);
        $complex->doctors()->findOrFail($doctorId);

        $validator = Validator::make($request->all(), [
            'chip_ids' => 'required|array',
            'chip_ids.*' => 'exists:complex_chips,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validChipIds = $complex->chips->pluck('id')->toArray();
        $chipIds = array_values(array_intersect($request->chip_ids, $validChipIds));

        $doctor = $complex->doctors()->where('doctors.id', $doctorId)->first();
        $doctor->complexChips()->detach($validChipIds);
        if (!empty($chipIds)) {
            $doctor->complexChips()->attach($chipIds);
        }

        return $this->getDoctorChips($complexId, $doctorId);
    }

    public function destroy($id)
    {
        $complex = Complex::with('doctors')->findOrFail($id);
        $complex->delete();

        return response()->json([
            'message' => 'Complex deleted successfully',
        ]);
    }
}

