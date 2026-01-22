<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Branch, Product, Customer, Sale};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard summary statistics
     */
    public function summary(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $branchId = $request->query('branch_id');

        // Build base queries with tenant filter
        $salesQuery = Sale::where('tenant_id', $tenantId);
        $productsQuery = Product::where('tenant_id', $tenantId);
        $customersQuery = Customer::where('tenant_id', $tenantId);
        $branchesQuery = Branch::where('tenant_id', $tenantId)->where('is_active', true);

        // Apply branch filter if provided
        if ($branchId) {
            $salesQuery->where('branch_id', $branchId);
        }

        // Get statistics
        $stats = [
            'total_sales_today' => (clone $salesQuery)
                ->whereDate('sale_date', today())
                ->count(),
            
            'revenue_today' => (clone $salesQuery)
                ->whereDate('sale_date', today())
                ->sum('total_amount'),
            
            'total_sales_month' => (clone $salesQuery)
                ->whereMonth('sale_date', now()->month)
                ->whereYear('sale_date', now()->year)
                ->count(),
            
            'revenue_month' => (clone $salesQuery)
                ->whereMonth('sale_date', now()->month)
                ->whereYear('sale_date', now()->year)
                ->sum('total_amount'),
            
            'total_products' => $productsQuery->count(),
            'total_customers' => $customersQuery->count(),
            'total_branches' => $branchesQuery->count(),
        ];

        // Get recent sales
        $recentSales = (clone $salesQuery)
            ->with(['customer', 'branch'])
            ->latest('sale_date')
            ->limit(5)
            ->get()
            ->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'customer_name' => $sale->customer->name ?? 'Walk-in',
                    'branch_name' => $sale->branch->name,
                    'total_amount' => $sale->total_amount,
                    'payment_status' => $sale->payment_status,
                    'sale_date' => $sale->sale_date->format('Y-m-d H:i'),
                ];
            });

        // Get all branches for selector
        $branches = $branchesQuery->select('id', 'name', 'code')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'recent_sales' => $recentSales,
                'branches' => $branches,
            ],
        ]);
    }
}
