<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Asset, Product, PurchaseOrder, Sale, StockMovement, StockOpname};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Sales Report - Daily/Monthly/Custom range
     */
    public function sales(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');

        // Restrict Branch Admin/Manager to their assigned branch
        if (!$request->user()->hasRole('owner')) {
            $employee = $request->user()->employee;
            if ($employee && $employee->branch_id) {
                $branchId = $employee->branch_id;
            }
        }

        $groupBy = $request->get('group_by', 'day'); // day, week, month

        $query = Sale::where('tenant_id', $tenantId)
            ->whereBetween('sale_date', [$startDate, $endDate]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        // Summary - using correct column names
        $summary = [
            'total_transactions' => (clone $query)->count(),
            'total_revenue' => (clone $query)->sum('total_amount'),
            'total_discount' => (clone $query)->sum('discount_amount'),
            'total_tax' => (clone $query)->sum('tax_amount'),
            'average_transaction' => (clone $query)->avg('total_amount') ?? 0,
        ];

        // Daily trend
        $dateFormat = match($groupBy) {
            'week' => "to_char(sale_date, 'IYYY-IW')",
            'month' => "to_char(sale_date, 'YYYY-MM')",
            default => "to_char(sale_date, 'YYYY-MM-DD')",
        };

        $dailyTrend = Sale::where('tenant_id', $tenantId)
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("$dateFormat as period, count(*) as transactions, sum(total_amount) as revenue")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        // Top products
        $topProducts = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.tenant_id', $tenantId)
            ->whereBetween('sales.sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('products.name, products.sku, sum(sale_items.quantity) as total_qty, sum(sale_items.subtotal) as total_revenue')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get();

        // Payment methods
        $paymentMethods = Sale::where('tenant_id', $tenantId)
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("payment_method, count(*) as count, sum(total_amount) as total")
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['start' => $startDate, 'end' => $endDate],
                'summary' => $summary,
                'daily_trend' => $dailyTrend,
                'top_products' => $topProducts,
                'payment_methods' => $paymentMethods,
            ],
        ]);
    }

    /**
     * Inventory Report - Stock levels, movements, alerts
     */
    public function inventory(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $branchId = $request->get('branch_id');

        // Restrict Branch Admin/Manager to their assigned branch
        if (!$request->user()->hasRole('owner')) {
            $employee = $request->user()->employee;
            if ($employee && $employee->branch_id) {
                $branchId = $employee->branch_id;
            }
        }

        // Stock levels - using purchase_price instead of cost_price
        $stockLevels = Product::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("
                count(*) as total_products,
                sum(stock) as total_stock,
                sum(stock * purchase_price) as total_stock_value,
                sum(case when stock <= min_stock then 1 else 0 end) as low_stock_count,
                sum(case when stock = 0 then 1 else 0 end) as out_of_stock_count
            ")
            ->first();

        // Low stock products
        $lowStockProducts = Product::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereColumn('stock', '<=', 'min_stock')
            ->where('is_active', true)
            ->select('id', 'name', 'sku', 'stock', 'min_stock')
            ->orderBy('stock')
            ->limit(20)
            ->get();

        // Stock by category - get category name from relation
        $stockByCategory = Product::where('products.tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('products.branch_id', $branchId))
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->selectRaw("categories.name as category, count(*) as product_count, sum(products.stock) as total_stock, sum(products.stock * products.purchase_price) as stock_value")
            ->groupBy('categories.id', 'categories.name')
            ->get();

        // Recent movements
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::now()->format('Y-m-d'));

        $movementSummary = StockMovement::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw("
                movement_type,
                count(*) as count,
                sum(quantity) as total_quantity
            ")
            ->groupBy('movement_type')
            ->get()
            ->keyBy('movement_type');

        return response()->json([
            'success' => true,
            'data' => [
                'stock_summary' => $stockLevels,
                'low_stock_products' => $lowStockProducts,
                'stock_by_category' => $stockByCategory,
                'movement_summary' => $movementSummary,
            ],
        ]);
    }

    /**
     * Financial Report - Revenue, costs, profit
     */
    public function financial(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', Carbon::now()->format('Y-m-d'));
        $branchId = $request->get('branch_id');

        // Restrict Branch Admin/Manager to their assigned branch
        if (!$request->user()->hasRole('owner')) {
            $employee = $request->user()->employee;
            if ($employee && $employee->branch_id) {
                $branchId = $employee->branch_id;
            }
        }

        // Revenue from sales - using correct column names
        $salesRevenue = Sale::where('tenant_id', $tenantId)
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("sum(total_amount) as revenue, sum(discount_amount) as discounts, sum(tax_amount) as taxes")
            ->first();

        // Cost of goods sold (COGS) - using purchase_price
        $cogs = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->where('sales.tenant_id', $tenantId)
            ->whereBetween('sales.sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw("sum(sale_items.quantity * products.purchase_price) as cogs")
            ->first();

        // Purchase costs
        $purchaseCosts = PurchaseOrder::where('tenant_id', $tenantId)
            ->where('status', 'received')
            ->whereBetween('order_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('total_amount');

        // Asset maintenance costs
        $maintenanceCosts = DB::table('asset_maintenances')
            ->join('assets', 'asset_maintenances.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->where('asset_maintenances.status', 'completed')
            ->whereBetween('asset_maintenances.completed_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('assets.branch_id', $branchId))
            ->sum('asset_maintenances.cost');

        $revenue = $salesRevenue->revenue ?? 0;
        $cogsAmount = $cogs->cogs ?? 0;
        $grossProfit = $revenue - $cogsAmount;
        $netProfit = $grossProfit - $maintenanceCosts;

        // Monthly trend
        $monthlyTrend = Sale::where('tenant_id', $tenantId)
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("to_char(sale_date, 'YYYY-MM') as month, sum(total_amount) as revenue")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => ['start' => $startDate, 'end' => $endDate],
                'summary' => [
                    'revenue' => $revenue,
                    'discounts' => $salesRevenue->discounts ?? 0,
                    'taxes' => $salesRevenue->taxes ?? 0,
                    'cogs' => $cogsAmount,
                    'gross_profit' => $grossProfit,
                    'gross_margin' => $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0,
                    'purchase_costs' => $purchaseCosts,
                    'maintenance_costs' => $maintenanceCosts,
                    'net_profit' => $netProfit,
                ],
                'monthly_trend' => $monthlyTrend,
            ],
        ]);
    }

    /**
     * Asset Report - Asset summary and depreciation
     */
    public function assets(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $branchId = $request->get('branch_id');

        // Restrict Branch Admin/Manager to their assigned branch
        if (!$request->user()->hasRole('owner')) {
            $employee = $request->user()->employee;
            if ($employee && $employee->branch_id) {
                $branchId = $employee->branch_id;
            }
        }

        $summary = Asset::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("
                count(*) as total_assets,
                sum(purchase_price) as total_purchase_value,
                sum(current_value) as total_current_value,
                sum(purchase_price) - sum(current_value) as total_depreciation
            ")
            ->first();

        $byCategory = Asset::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("category, count(*) as count, sum(current_value) as value")
            ->groupBy('category')
            ->get();

        $byCondition = Asset::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("condition, count(*) as count")
            ->groupBy('condition')
            ->get();

        $byStatus = Asset::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw("status, count(*) as count")
            ->groupBy('status')
            ->get();

        $upcomingMaintenance = Asset::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('next_maintenance_date')
            ->where('next_maintenance_date', '<=', Carbon::now()->addDays(30))
            ->select('id', 'code', 'name', 'next_maintenance_date')
            ->orderBy('next_maintenance_date')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'by_category' => $byCategory,
                'by_condition' => $byCondition,
                'by_status' => $byStatus,
                'upcoming_maintenance' => $upcomingMaintenance,
            ],
        ]);
    }

    /**
     * Dashboard overview summary
     */
    public function overview(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $branchId = null;

        // Restrict Branch Admin/Manager to their assigned branch
        if (!$request->user()->hasRole('owner')) {
            $employee = $request->user()->employee;
            if ($employee && $employee->branch_id) {
                $branchId = $employee->branch_id;
            }
        }

        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        // Today's sales
        $todaySales = Sale::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereDate('sale_date', $today)
            ->selectRaw("count(*) as transactions, coalesce(sum(total_amount), 0) as revenue")
            ->first();

        // This month's sales
        $monthSales = Sale::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereBetween('sale_date', [$thisMonth, $today])
            ->selectRaw("count(*) as transactions, coalesce(sum(total_amount), 0) as revenue")
            ->first();

        // Low stock alerts (Product is tenant-wide)
        $lowStockCount = Product::where('tenant_id', $tenantId)
            ->whereColumn('stock', '<=', 'min_stock')
            ->where('is_active', true)
            ->count();

        // Pending POs
        $pendingPOs = PurchaseOrder::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', ['draft', 'submitted', 'approved'])
            ->count();

        // Assets needing maintenance
        $assetsMaintenance = Asset::where('tenant_id', $tenantId)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->where('next_maintenance_date', '<=', $today)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'transactions' => $todaySales->transactions,
                    'revenue' => $todaySales->revenue,
                ],
                'this_month' => [
                    'transactions' => $monthSales->transactions,
                    'revenue' => $monthSales->revenue,
                ],
                'alerts' => [
                    'low_stock' => $lowStockCount,
                    'pending_po' => $pendingPOs,
                    'assets_maintenance' => $assetsMaintenance,
                ],
            ],
        ]);
    }
}
