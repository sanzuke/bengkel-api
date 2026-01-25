<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $query = Branch::where('tenant_id', $tenantId);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('address', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $branches = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $branches,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'address' => 'required|string',
            'phone' => 'required|string|max:20',
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['tenant_id'] = $tenantId;

        // If this is set as main, unset other main branches
        if (!empty($validated['is_main']) && $validated['is_main']) {
            Branch::where('tenant_id', $tenantId)->update(['is_main' => false]);
        }

        $branch = Branch::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Branch created successfully',
            'data' => $branch,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $tenantId = $request->user()->tenant_id;
        $branch = Branch::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $branch,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $tenantId = $request->user()->tenant_id;
        $branch = Branch::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'address' => 'required|string',
            'phone' => 'required|string|max:20',
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // If this is set as main, unset other main branches
        if (!empty($validated['is_main']) && $validated['is_main']) {
            Branch::where('tenant_id', $tenantId)
                ->where('id', '!=', $id)
                ->update(['is_main' => false]);
        }

        $branch->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Branch updated successfully',
            'data' => $branch,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $tenantId = $request->user()->tenant_id;
        $branch = Branch::where('tenant_id', $tenantId)->findOrFail($id);

        // Check if branch has related data
        if ($branch->sales()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete branch because it has related sales data.',
            ], 422);
        }

        $branch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Branch deleted successfully',
        ]);
    }
}
