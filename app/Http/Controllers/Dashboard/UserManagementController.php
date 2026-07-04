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
        $search = $request->get('search', $request->get('q'));
        $ids = $request->get('ids');
        $idList = $ids ? (is_array($ids) ? $ids : array_filter(array_map('intval', explode(',', $ids)))) : [];
        $hasSearch = $search && strlen(trim($search)) > 0;

        if ($hasSearch && !empty($idList)) {
            $term = trim($search);
            $query->where(function ($q) use ($term, $idList) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhereIn('id', $idList);
            });
        } elseif ($hasSearch) {
            $term = trim($search);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        } elseif (!empty($idList)) {
            $query->whereIn('id', $idList);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('registered_from')) {
            $query->whereDate('created_at', '>=', $request->registered_from);
        }

        if ($request->filled('registered_to')) {
            $query->whereDate('created_at', '<=', $request->registered_to);
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
