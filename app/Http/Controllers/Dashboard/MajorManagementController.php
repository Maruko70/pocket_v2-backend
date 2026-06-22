<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Major;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MajorManagementController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 50);
        $page = $request->get('page', 1);
        $search = $request->get('search', $request->get('q'));
        $ids = $request->get('ids');

        $query = Major::with('doctors');
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
        $majors = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'majors' => $majors->items(),
            'pagination' => [
                'current_page' => $majors->currentPage(),
                'per_page' => $majors->perPage(),
                'total' => $majors->total(),
                'last_page' => $majors->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'icon' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = ['name' => $request->name];

        if ($request->hasFile('icon')) {
            $data['icon'] = $request->file('icon')->store('majors', 'public');
        }

        $major = Major::create($data);

        return response()->json([
            'message' => 'Major created successfully',
            'major' => $major,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $major = Major::with('doctors')->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'icon' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['name']);

        if ($request->hasFile('icon')) {
            if ($major->icon) {
                Storage::disk('public')->delete($major->icon);
            }
            $data['icon'] = $request->file('icon')->store('majors', 'public');
        }

        $major->update($data);

        return response()->json([
            'message' => 'Major updated successfully',
            'major' => $major,
        ]);
    }

    public function destroy($id)
    {
        $major = Major::with('doctors')->findOrFail($id);
        $major->delete();

        return response()->json([
            'message' => 'Major deleted successfully',
        ]);
    }
}

