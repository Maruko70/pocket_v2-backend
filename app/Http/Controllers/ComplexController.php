<?php

namespace App\Http\Controllers;

use App\Models\Complex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ComplexController extends Controller
{
    /**
     * Ensure logo is a full URL when present (same pattern as Doctor image).
     */
    private function normalizeComplexForJson(Complex $complex): array
    {
        $arr = $complex->toArray();
        if (!empty($arr['logo'])) {
            $arr['logo'] = url(Storage::url($arr['logo']));
        }
        return $arr;
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);
        $sortBy = $request->get('sort_by', 'name'); // name, created_at
        $sortOrder = $request->get('sort_order', 'asc'); // asc, desc

        $complexesQuery = Complex::active()->with([
            'doctors' => function ($q) {
                $q->where('status', 'active')->with(['majors', 'reviews']);
            },
            'majors',
            'chips',
        ]);

        if ($query) {
            $complexesQuery->where('name', 'like', "%{$query}%");
        }

        // Sorting
        if ($sortBy === 'created_at') {
            $complexesQuery->orderBy('created_at', $sortOrder);
        } else {
            $complexesQuery->orderBy('name', $sortOrder);
        }

        $complexes = $complexesQuery->paginate($perPage, ['*'], 'page', $page);
        $items = collect($complexes->items())->map(fn ($c) => $this->normalizeComplexForJson($c))->values()->all();

        return response()->json([
            'complexes' => $items,
            'pagination' => [
                'current_page' => $complexes->currentPage(),
                'per_page' => $complexes->perPage(),
                'total' => $complexes->total(),
                'last_page' => $complexes->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $complexId = $id;
        $complex = Complex::active()
            ->with([
                'doctors' => function ($query) use ($complexId) {
                    $query->where('status', 'active')
                        ->with([
                            'majors',
                            'reviews',
                            'complexChips' => function ($q) use ($complexId) {
                                $q->where('complex_id', $complexId);
                            },
                        ]);
                },
                'majors',
                'chips',
            ])
            ->findOrFail($id);

        return response()->json([
            'complex' => $this->normalizeComplexForJson($complex),
        ]);
    }
}

