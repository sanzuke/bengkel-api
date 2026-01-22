<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Asset, AssetMaintenance};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssetController extends Controller
{
    /**
     * Get all assets with pagination
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = Asset::where('tenant_id', $tenantId)
            ->with(['branch', 'creator']);

        // Filter by branch
        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by condition
        if ($request->has('condition')) {
            $query->where('condition', $request->condition);
        }

        // Search by name, code, or serial number
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('serial_number', 'ilike', "%{$search}%");
            });
        }

        $assets = $query->orderBy('name')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $assets,
        ]);
    }

    /**
     * Create a new asset
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:100',
            'category' => 'required|string|max:50',
            'description' => 'nullable|string',
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'current_value' => 'nullable|numeric|min:0',
            'condition' => 'nullable|string|max:30',
            'status' => 'nullable|string|max:30',
            'location' => 'nullable|string|max:100',
            'warranty_expiry' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        // Generate asset code
        $lastAsset = Asset::where('tenant_id', $tenantId)
            ->orderBy('id', 'desc')
            ->first();
        $counter = $lastAsset ? (int)substr($lastAsset->code, -4) + 1 : 1;
        $code = 'AST-' . str_pad($counter, 4, '0', STR_PAD_LEFT);

        $asset = Asset::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'code' => $code,
            'condition' => $validated['condition'] ?? 'good',
            'status' => $validated['status'] ?? 'active',
            'created_by' => $userId,
        ]);

        $asset->load(['branch', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Asset created successfully',
            'data' => $asset,
        ], 201);
    }

    /**
     * Get asset detail
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $asset = Asset::where('tenant_id', $tenantId)
            ->with(['branch', 'creator', 'maintenances' => function ($q) {
                $q->orderBy('maintenance_date', 'desc');
            }])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }

    /**
     * Update asset
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $asset = Asset::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'name' => 'required|string|max:100',
            'category' => 'required|string|max:50',
            'description' => 'nullable|string',
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'current_value' => 'nullable|numeric|min:0',
            'condition' => 'nullable|string|max:30',
            'status' => 'nullable|string|max:30',
            'location' => 'nullable|string|max:100',
            'warranty_expiry' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $asset->update($validated);
        $asset->load(['branch', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Asset updated successfully',
            'data' => $asset,
        ]);
    }

    /**
     * Delete asset
     */
    public function destroy(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $asset = Asset::where('tenant_id', $tenantId)->findOrFail($id);
        
        $asset->maintenances()->delete();
        $asset->delete();

        return response()->json([
            'success' => true,
            'message' => 'Asset deleted successfully',
        ]);
    }

    /**
     * Get categories
     */
    public function categories()
    {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => 'equipment', 'label' => 'Peralatan'],
                ['value' => 'tool', 'label' => 'Alat'],
                ['value' => 'vehicle', 'label' => 'Kendaraan'],
                ['value' => 'furniture', 'label' => 'Furniture'],
                ['value' => 'electronics', 'label' => 'Elektronik'],
            ],
        ]);
    }

    /**
     * Add maintenance record
     */
    public function addMaintenance(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $asset = Asset::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'maintenance_type' => 'required|string|max:50',
            'maintenance_date' => 'required|date',
            'status' => 'nullable|string|max:30',
            'performed_by' => 'nullable|string|max:100',
            'cost' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $maintenance = $asset->maintenances()->create([
            ...$validated,
            'status' => $validated['status'] ?? 'scheduled',
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Maintenance record added',
            'data' => $maintenance,
        ], 201);
    }

    /**
     * Complete maintenance
     */
    public function completeMaintenance(Request $request, $assetId, $maintenanceId)
    {
        $tenantId = $request->user()->tenant_id;

        $asset = Asset::where('tenant_id', $tenantId)->findOrFail($assetId);
        $maintenance = AssetMaintenance::where('asset_id', $asset->id)->findOrFail($maintenanceId);

        $validated = $request->validate([
            'findings' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'next_maintenance' => 'nullable|date',
            'condition_after' => 'nullable|string|max:30',
        ]);

        $maintenance->update([
            'status' => 'completed',
            'completed_date' => now(),
            'findings' => $validated['findings'] ?? null,
            'actions_taken' => $validated['actions_taken'] ?? null,
            'cost' => $validated['cost'] ?? $maintenance->cost,
            'next_maintenance' => $validated['next_maintenance'] ?? null,
        ]);

        // Update asset
        $asset->update([
            'last_maintenance_date' => now(),
            'next_maintenance_date' => $validated['next_maintenance'] ?? null,
            'condition' => $validated['condition_after'] ?? $asset->condition,
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Maintenance completed',
            'data' => $maintenance,
        ]);
    }

    /**
     * Get summary stats
     */
    public function summary(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $stats = [
            'total' => Asset::where('tenant_id', $tenantId)->count(),
            'active' => Asset::where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'in_maintenance' => Asset::where('tenant_id', $tenantId)->where('status', 'in_maintenance')->count(),
            'total_value' => Asset::where('tenant_id', $tenantId)->where('status', 'active')->sum('current_value'),
            'by_category' => Asset::where('tenant_id', $tenantId)
                ->selectRaw('category, count(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category'),
            'by_condition' => Asset::where('tenant_id', $tenantId)
                ->selectRaw('condition, count(*) as count')
                ->groupBy('condition')
                ->pluck('count', 'condition'),
            'upcoming_maintenance' => Asset::where('tenant_id', $tenantId)
                ->whereNotNull('next_maintenance_date')
                ->where('next_maintenance_date', '<=', now()->addDays(30))
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
