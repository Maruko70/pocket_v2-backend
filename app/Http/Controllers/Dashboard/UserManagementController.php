<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    /**
     * List app users (paginated, optional search).
     */
    public function index(Request $request)
    {
        $query = User::query()->withCount('reservations');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $users = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'users' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /**
     * Show a single app user with recent reservations.
     */
    public function show($id)
    {
        $user = User::withCount('reservations')
            ->with(['reservations' => function ($q) {
                $q->with(['doctor', 'complex'])->orderBy('date', 'desc')->limit(10);
            }])
            ->findOrFail($id);

        return response()->json([
            'user' => $user,
        ]);
    }
}
