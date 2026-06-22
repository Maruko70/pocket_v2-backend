<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Rules\SudanPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserManagementController extends Controller
{
    /**
     * List dashboard admin users.
     */
    public function index(Request $request)
    {
        $query = AdminUser::query();

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $admins = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'admins' => $admins->items(),
            'pagination' => [
                'current_page' => $admins->currentPage(),
                'per_page' => $admins->perPage(),
                'total' => $admins->total(),
                'last_page' => $admins->lastPage(),
            ],
        ]);
    }

    /**
     * Store a new admin user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', new SudanPhoneNumber(), 'unique:admin_users,phone'],
            'password' => 'required|string|min:6',
            'role' => 'nullable|string|in:admin,manager',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:' . implode(',', config('dashboard.permissions', [])),
        ]);

        $validated['phone'] = SudanPhoneNumber::normalize($validated['phone']);
        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = $validated['role'] ?? 'manager';
        $validated['permissions'] = $validated['permissions'] ?? [];

        $admin = AdminUser::create($validated);

        return response()->json([
            'message' => 'Admin user created successfully',
            'admin' => $admin,
        ], 201);
    }

    /**
     * Show a single admin user.
     */
    public function show($id)
    {
        $admin = AdminUser::findOrFail($id);
        return response()->json(['admin' => $admin]);
    }

    /**
     * Update an admin user.
     */
    public function update(Request $request, $id)
    {
        $admin = AdminUser::findOrFail($id);

        $rules = [
            'name' => 'sometimes|string|max:255',
            'phone' => ['sometimes', 'string', new SudanPhoneNumber(), Rule::unique('admin_users')->ignore($admin->id)],
            'role' => 'nullable|string|in:admin,manager',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|in:' . implode(',', config('dashboard.permissions', [])),
        ];
        if ($request->filled('password')) {
            $rules['password'] = 'string|min:6';
        }
        $validated = $request->validate($rules);

        if (isset($validated['phone'])) {
            $validated['phone'] = SudanPhoneNumber::normalize($validated['phone']);
        }
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }
        if (array_key_exists('permissions', $validated) && $validated['permissions'] === null) {
            $validated['permissions'] = [];
        }

        $admin->update($validated);

        return response()->json([
            'message' => 'Admin user updated successfully',
            'admin' => $admin->fresh(),
        ]);
    }

    /**
     * Remove an admin user. Cannot delete self or the last admin.
     */
    public function destroy(Request $request, $id)
    {
        $admin = AdminUser::findOrFail($id);
        $currentUser = $request->user();

        if ($currentUser->id === (int) $id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if (AdminUser::count() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last admin user.',
            ], 422);
        }

        $admin->delete();
        $admin->tokens()->delete();

        return response()->json([
            'message' => 'Admin user deleted successfully',
        ]);
    }
}
