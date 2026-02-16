<?php

use App\Http\Controllers\Api\{ActivityLogController, AssetController, AttendanceController, AuthController, BranchController, CategoryController, CustomerController, DashboardController, EmployeeController, InventoryController, ProductController, PurchaseOrderController, ReceiptController, ReportController, RoleController, SaleController, SettingsController, StockOpnameController, SupplierController, UserController};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    
    // Kiosk Routes (Public)
    Route::get('/kiosk/employees', [AttendanceController::class, 'kioskEmployees']);
    Route::post('/kiosk/clock-in', [AttendanceController::class, 'kioskClockIn']);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        
        // Dashboard
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        
        // Products
        Route::get('/products/categories', [ProductController::class, 'categories']);
        Route::get('/products/suppliers', [ProductController::class, 'suppliers']);
        Route::get('/products/import-template', [ProductController::class, 'importTemplate']);
        Route::post('/products/import', [ProductController::class, 'import']);
        Route::apiResource('products', ProductController::class);
        
        // Sales / POS
        Route::get('/pos/products', [SaleController::class, 'searchProducts']);
        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales', [SaleController::class, 'index']);
        Route::get('/sales/{id}', [SaleController::class, 'show']);
        
        // Role Management
        Route::get('/permissions', [RoleController::class, 'permissions']);
        Route::post('/roles/{id}/permissions', [RoleController::class, 'syncPermissions']);
        Route::apiResource('roles', RoleController::class);
        
        // User Management
        Route::post('/users/{id}/roles', [UserController::class, 'assignRoles']);
        Route::post('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::apiResource('users', UserController::class);
        
        // Suppliers
        Route::post('/suppliers/{id}/toggle-status', [SupplierController::class, 'toggleStatus']);
        Route::apiResource('suppliers', SupplierController::class);
        
        // Categories
        Route::get('/categories/tree', [CategoryController::class, 'tree']);
        Route::apiResource('categories', CategoryController::class);
        
        // Inventory Management
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
        Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut']);
        Route::post('/inventory/adjustment', [InventoryController::class, 'adjustment']);
        Route::get('/inventory/summary', [InventoryController::class, 'summary']);
        Route::get('/inventory/branches', [InventoryController::class, 'branches']);
        
        // Purchase Orders
        Route::post('/purchase-orders/{id}/submit', [PurchaseOrderController::class, 'submit']);
        Route::post('/purchase-orders/{id}/approve', [PurchaseOrderController::class, 'approve']);
        Route::post('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive']);
        Route::post('/purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        
        // Stock Opname
        Route::post('/stock-opname/{id}/start', [StockOpnameController::class, 'start']);
        Route::put('/stock-opname/{id}/items', [StockOpnameController::class, 'updateItems']);
        Route::post('/stock-opname/{id}/complete', [StockOpnameController::class, 'complete']);
        Route::post('/stock-opname/{id}/cancel', [StockOpnameController::class, 'cancel']);
        Route::apiResource('stock-opname', StockOpnameController::class);
        
        // Assets
        Route::get('/assets/categories', [AssetController::class, 'categories']);
        Route::get('/assets/summary', [AssetController::class, 'summary']);
        Route::post('/assets/{id}/maintenance', [AssetController::class, 'addMaintenance']);
        Route::post('/assets/{id}/maintenance/{maintenanceId}/complete', [AssetController::class, 'completeMaintenance']);
        Route::apiResource('assets', AssetController::class);
        
        // Reports
        Route::get('/reports/overview', [ReportController::class, 'overview']);
        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/inventory', [ReportController::class, 'inventory']);
        Route::get('/reports/financial', [ReportController::class, 'financial']);
        Route::get('/reports/assets', [ReportController::class, 'assets']);

        // Branches
        Route::apiResource('branches', BranchController::class);

        // Receipt
        Route::get('/sales/{id}/receipt', [ReceiptController::class, 'show']);

        // Attendance
        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::get('/attendance/summary', [AttendanceController::class, 'summary']);
        Route::get('/attendance/today', [AttendanceController::class, 'todayStatus']);
        Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);
        Route::post('/attendance/manual', [AttendanceController::class, 'storeManual']);
        Route::post('/attendance/register-face', [AttendanceController::class, 'registerFace']);

        // Employees
        Route::get('/employees/available-users', [EmployeeController::class, 'getAvailableUsers']);
        Route::apiResource('employees', EmployeeController::class);

        // Customers
        Route::apiResource('customers', CustomerController::class);

        // Settings
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings', [SettingsController::class, 'update']);

        // Activity Logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/activity-logs/{id}', [ActivityLogController::class, 'show']);
    });
});

