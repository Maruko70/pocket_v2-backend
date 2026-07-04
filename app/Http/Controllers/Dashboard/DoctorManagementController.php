<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\DoctorUser;
use App\Rules\SudanPhoneNumber;
use App\Services\DoctorStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DoctorManagementController extends Controller
{
    protected $statusService;

    public function __construct(DoctorStatusService $statusService)
    {
        $this->statusService = $statusService;
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $status = $request->get('status');
        $search = $request->get('search', $request->get('q'));
        $ids = $request->get('ids');
        $idList = $ids ? (is_array($ids) ? $ids : array_filter(array_map('intval', explode(',', $ids)))) : [];
        $hasSearch = $search && strlen(trim($search)) > 0;

        $query = Doctor::with(['complexes', 'majors']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($hasSearch && !empty($idList)) {
            $term = trim($search);
            $query->where(function ($q) use ($term, $idList) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhereIn('id', $idList);
            });
        } elseif ($hasSearch) {
            $query->where('name', 'like', '%'.trim($search).'%');
        } elseif (!empty($idList)) {
            $query->whereIn('id', $idList);
        }

        if ($request->filled('complex_id')) {
            $complexId = $request->get('complex_id');
            $query->whereHas('complexes', fn ($q) => $q->where('complexes.id', $complexId));
        }

        if ($request->filled('major_id')) {
            $majorId = $request->get('major_id');
            $query->whereHas('majors', fn ($q) => $q->where('majors.id', $majorId));
        }

        $sortBy = in_array($request->get('sort_by'), ['name', 'status', 'created_at'], true)
            ? $request->get('sort_by')
            : 'id';
        $sortDir = $request->get('sort_dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        $doctors = $query->paginate($perPage, ['*'], 'page', $page);

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

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
            'complex_ids' => 'nullable|array',
            'complex_ids.*' => 'exists:complexes,id',
            'major_ids' => 'nullable|array',
            'major_ids.*' => 'exists:majors,id',
            'account_phone' => ['nullable', 'string', new SudanPhoneNumber()],
            'account_password' => 'nullable|string|min:6|required_with:account_phone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If phone provided without password or vice versa
        if ($request->filled('account_phone') xor $request->filled('account_password')) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'account_phone' => ['Both phone and password are required to create a doctor account.'],
                ],
            ], 422);
        }

        // Check phone uniqueness before creating doctor (only if creating account)
        if ($request->filled('account_phone')) {
            $normalizedPhone = SudanPhoneNumber::normalize($request->account_phone);
            if (DoctorUser::where('phone', $normalizedPhone)->exists()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['account_phone' => ['This phone number is already used by another doctor account.']],
                ], 422);
            }
        }

        $data = $request->only(['name', 'bio']);
        $data['status'] = 'active';

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('doctors', 'public');
        }

        $doctor = Doctor::create($data);

        if ($request->has('complex_ids')) {
            $doctor->complexes()->attach($request->complex_ids);
        }

        if ($request->has('major_ids')) {
            $doctor->majors()->attach($request->major_ids);
        }

        // Create doctor user (portal login) if phone and password provided
        if ($request->filled('account_phone') && $request->filled('account_password')) {
            DoctorUser::create([
                'doctor_id' => $doctor->id,
                'phone' => SudanPhoneNumber::normalize($request->account_phone),
                'password' => $request->account_password,
            ]);
        }

        // Clear cache
        Cache::forget('doctors_search_*');

        return response()->json([
            'message' => 'Doctor created successfully',
            'doctor' => $doctor->load(['complexes', 'majors']),
        ], 201);
    }

    public function show($id)
    {
        $doctor = Doctor::with(['complexes', 'majors', 'statusHistory', 'doctorUser'])->findOrFail($id);

        $doctorArray = $doctor->toArray();
        if ($doctor->doctorUser) {
            $doctorArray['doctor_user'] = ['phone' => $doctor->doctorUser->phone];
        }

        return response()->json([
            'doctor' => $doctorArray,
        ]);
    }

    public function update(Request $request, $id)
    {
        $doctor = Doctor::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'bio' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
            'complex_ids' => 'nullable|array',
            'complex_ids.*' => 'exists:complexes,id',
            'major_ids' => 'nullable|array',
            'major_ids.*' => 'exists:majors,id',
            'account_phone' => ['nullable', 'string', new SudanPhoneNumber()],
            'account_password' => 'nullable|string|min:6|required_with:account_phone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->filled('account_phone') xor $request->filled('account_password')) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['account_phone' => ['Both phone and password are required to create or reset portal account.']],
            ], 422);
        }

        $data = $request->only(['name', 'bio']);

        if ($request->hasFile('image')) {
            if ($doctor->image) {
                Storage::disk('public')->delete($doctor->image);
            }
            $data['image'] = $request->file('image')->store('doctors', 'public');
        }

        $doctor->update($data);

        if ($request->has('complex_ids')) {
            $doctor->complexes()->sync($request->complex_ids);
        }

        if ($request->has('major_ids')) {
            $doctor->majors()->sync($request->major_ids);
        }

        // Create or reset doctor portal account when both phone and password provided
        if ($request->filled('account_phone') && $request->filled('account_password')) {
            $normalizedPhone = SudanPhoneNumber::normalize($request->account_phone);
            $existing = DoctorUser::where('phone', $normalizedPhone)->where('doctor_id', '!=', $doctor->id)->first();
            if ($existing) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['account_phone' => ['This phone number is already used by another doctor account.']],
                ], 422);
            }
            DoctorUser::updateOrCreate(
                ['doctor_id' => $doctor->id],
                [
                    'phone' => $normalizedPhone,
                    'password' => $request->account_password,
                ]
            );
        }

        // Clear cache
        Cache::forget('doctor_' . $doctor->id);
        Cache::forget('doctors_search_*');

        return response()->json([
            'message' => 'Doctor updated successfully',
            'doctor' => $doctor->load(['complexes', 'majors', 'doctorUser']),
        ]);
    }

    public function destroy($id)
    {
        $doctor = Doctor::findOrFail($id);
        $doctor->delete();

        return response()->json([
            'message' => 'Doctor deleted successfully',
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $doctor = Doctor::with(['complexes', 'majors', 'statusHistory'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,unavailable,suspended,disabled',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after:from_date',
            'is_auto_reactivate' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->statusService->setDoctorStatus(
            $doctor,
            $request->status,
            \Carbon\Carbon::parse($request->from_date, 'Africa/Khartoum'),
            $request->to_date ? \Carbon\Carbon::parse($request->to_date, 'Africa/Khartoum') : null,
            $request->is_auto_reactivate ?? false
        );

        return response()->json([
            'message' => 'Doctor status updated successfully',
            'doctor' => $doctor->fresh(),
        ]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_ids' => 'required|array',
            'doctor_ids.*' => 'exists:doctors,id',
            'status' => 'required|in:active,unavailable,suspended,disabled',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date|after:from_date',
            'is_auto_reactivate' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $doctors = Doctor::whereIn('id', $request->doctor_ids)->get();
        $updated = [];

        foreach ($doctors as $doctor) {
            $this->statusService->setDoctorStatus(
                $doctor,
                $request->status,
                \Carbon\Carbon::parse($request->from_date, 'Africa/Khartoum'),
                $request->to_date ? \Carbon\Carbon::parse($request->to_date, 'Africa/Khartoum') : null,
                $request->is_auto_reactivate ?? false
            );
            $updated[] = $doctor->fresh();
        }

        return response()->json([
            'message' => 'Doctor statuses updated successfully',
            'count' => count($updated),
            'doctors' => $updated,
        ]);
    }

    /**
     * Create or update doctor portal account (phone + password) for a doctor.
     */
    public function storeAccount(Request $request, $id)
    {
        $doctor = Doctor::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', new SudanPhoneNumber()],
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $normalizedPhone = SudanPhoneNumber::normalize($request->phone);

        // Ensure phone is not used by another doctor user
        $existing = DoctorUser::where('phone', $normalizedPhone)->where('doctor_id', '!=', $doctor->id)->first();
        if ($existing) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['phone' => ['This phone number is already used by another doctor account.']],
            ], 422);
        }

        $doctorUser = DoctorUser::updateOrCreate(
            ['doctor_id' => $doctor->id],
            [
                'phone' => $normalizedPhone,
                'password' => $request->password,
            ]
        );

        return response()->json([
            'message' => 'Doctor account saved successfully',
            'doctor_user' => [
                'id' => $doctorUser->id,
                'doctor_id' => $doctorUser->doctor_id,
                'phone' => $doctorUser->phone,
            ],
        ], $doctorUser->wasRecentlyCreated ? 201 : 200);
    }

    public function featured()
    {
        $doctors = Doctor::active()
            ->featured()
            ->with(['complexes', 'majors'])
            ->get();

        return response()->json([
            'doctors' => $doctors,
            'doctor_ids' => $doctors->pluck('id')->values(),
        ]);
    }

    public function updateFeatured(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_ids' => 'present|array|max:6',
            'doctor_ids.*' => 'integer|distinct|exists:doctors,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $doctorIds = $request->input('doctor_ids', []);

        if ($doctorIds !== []) {
            $activeCount = Doctor::whereIn('id', $doctorIds)
                ->where('status', 'active')
                ->count();

            if ($activeCount !== count($doctorIds)) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => [
                        'doctor_ids' => ['Only active doctors can be featured.'],
                    ],
                ], 422);
            }
        }

        DB::transaction(function () use ($doctorIds) {
            Doctor::query()->update(['featured_sort' => null]);

            foreach ($doctorIds as $index => $doctorId) {
                Doctor::where('id', $doctorId)->update([
                    'featured_sort' => $index + 1,
                ]);
            }
        });

        Cache::forget('doctors_featured_curated');

        return response()->json([
            'message' => 'Featured doctors updated successfully',
            'doctor_ids' => $doctorIds,
        ]);
    }
}
