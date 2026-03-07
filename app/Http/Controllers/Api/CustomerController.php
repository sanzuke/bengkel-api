<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $query = Customer::where('tenant_id', $tenantId)
            ->with(['branch', 'vehicles']);

        // Search by name, phone, email, or customer code
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('customer_code', 'ilike', "%{$search}%");
            });
        }

        // Filter by customer_type
        if ($request->has('customer_type') && $request->customer_type) {
            $query->where('customer_type', $request->customer_type);
        }

        // Filter by branch
        if (!$user->hasRole('owner')) {
             $branchId = $user->employee->branch_id ?? $user->branches()->first()?->id;
             if ($branchId) {
                $query->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)
                      ->orWhereNull('branch_id');
                });
             } else {
                 $query->whereRaw('1 = 0');
             }
        } elseif ($request->has('branch_id') && $request->branch_id) {
            if ($request->branch_id === 'null') {
                $query->whereNull('branch_id');
            } else {
                $query->where('branch_id', $request->branch_id);
            }
        }

        // Add sales count & total spend
        $query->withCount('sales')
              ->withSum('sales', 'total_amount');

        $perPage = $request->get('per_page', 20);
        $customers = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'customer_type' => 'nullable|in:walk_in,regular,reseller,member',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $tenantId = $user->tenant_id;

        // Auto-assign branch if user is branch restricted
        if (!$user->hasRole('owner') && $user->employee && $user->employee->branch_id) {
            $validated['branch_id'] = $user->employee->branch_id;
        }

        // Set defaults
        if (empty($validated['customer_type'])) {
            $validated['customer_type'] = 'regular';
        }

        // Generate customer code
        $lastCustomer = Customer::where('tenant_id', $tenantId)
            ->latest('id')
            ->first();
        
        $counter = $lastCustomer ? (int)substr($lastCustomer->customer_code, -4) + 1 : 1;
        $customerCode = 'CUST-' . str_pad($counter, 4, '0', STR_PAD_LEFT);

        $customer = Customer::create([
            'tenant_id' => $tenantId,
            'customer_code' => $customerCode,
            ...$validated
        ]);

        $customer->load(['branch', 'vehicles']);

        return response()->json([
            'success' => true,
            'message' => 'Pelanggan berhasil ditambahkan',
            'data' => $customer,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $customer = Customer::where('tenant_id', $tenantId)
            ->with(['branch', 'vehicles', 'sales' => function($q) {
                $q->with(['branch', 'items.product'])->latest()->limit(10);
            }])
            ->withCount('sales')
            ->withSum('sales', 'total_amount')
            ->findOrFail($id);

        // Check access
        if (!$user->hasRole('owner') && $user->employee && $user->employee->branch_id) {
            if ($customer->branch_id && $customer->branch_id != $user->employee->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this customer',
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $customer,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'customer_type' => 'nullable|in:walk_in,regular,reseller,member',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $tenantId = $user->tenant_id;

        $customer = Customer::where('tenant_id', $tenantId)->findOrFail($id);

        // Check access
        if (!$user->hasRole('owner') && $user->employee && $user->employee->branch_id) {
            if ($customer->branch_id && $customer->branch_id != $user->employee->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this customer',
                ], 403);
            }
            // Prevent changing branch if not owner
            unset($validated['branch_id']);
        }

        $customer->update($validated);
        $customer->load(['branch', 'vehicles']);

        return response()->json([
            'success' => true,
            'message' => 'Pelanggan berhasil diperbarui',
            'data' => $customer,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $customer = Customer::where('tenant_id', $tenantId)->findOrFail($id);

        // Check access
        if (!$user->hasRole('owner') && $user->employee && $user->employee->branch_id) {
            if ($customer->branch_id && $customer->branch_id != $user->employee->branch_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this customer',
                ], 403);
            }
        }

        // Check if customer has sales
        if ($customer->sales()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Pelanggan tidak bisa dihapus karena memiliki riwayat transaksi',
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pelanggan berhasil dihapus',
        ]);
    }
}
