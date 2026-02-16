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
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('customer_code', 'ilike', "%{$search}%");
            });
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
        } elseif ($request->has('branch_id')) {
            // For owner filtering
            if ($request->branch_id === 'null') {
                $query->whereNull('branch_id');
            } else {
                $query->where('branch_id', $request->branch_id);
            }
        }

        $customers = $query->latest()->paginate(20);

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
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $tenantId = $user->tenant_id;

        // Auto-assign branch if user is branch restricted
        if (!$user->hasRole('owner') && $user->employee && $user->employee->branch_id) {
            $validated['branch_id'] = $user->employee->branch_id;
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

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
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
                $q->latest()->limit(5);
            }])
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

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully',
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

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully',
        ]);
    }
}
