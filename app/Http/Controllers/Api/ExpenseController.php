<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    /**
     * Category List
     */
    public function categories(Request $request)
    {
        $categories = ExpenseCategory::where('tenant_id', $request->user()->tenant_id)
            ->withCount('expenses')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Store Category
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $category = ExpenseCategory::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $validated['name'],
            'description' => $validated['description'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kategori pengeluaran berhasil dibuat',
            'data' => $category,
        ]);
    }

    /**
     * Update Category
     */
    public function updateCategory(Request $request, $id)
    {
        $category = ExpenseCategory::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori pengeluaran berhasil diupdate',
            'data' => $category,
        ]);
    }

    /**
     * Delete Category
     */
    public function destroyCategory(Request $request, $id)
    {
        $category = ExpenseCategory::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);

        if ($category->expenses()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori tidak bisa dihapus karena memiliki data pengeluaran',
            ], 400);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori pengeluaran berhasil dihapus',
        ]);
    }

    /**
     * Expense List with Pagination and Filters
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $user = $request->user();

        $query = Expense::where('tenant_id', $tenantId)
            ->with(['category', 'branch', 'creator']);

        // Branch filter
        if (!$user->hasRole('owner')) {
            $branchId = $user->employee->branch_id ?? $user->branches()->first()?->id;
            if ($branchId) {
                $query->where('branch_id', $branchId);
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Search reference or notes
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('reference_number', 'ilike', "%{$search}%")
                  ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }

        // Category filter
        if ($request->has('category_id')) {
            $query->where('expense_category_id', $request->category_id);
        }

        // Date range
        if ($request->has('start_date')) {
            $query->whereDate('expense_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('expense_date', '<=', $request->end_date);
        }

        $expenses = $query->latest('expense_date')->latest('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $expenses,
        ]);
    }

    /**
     * Store Expense
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'payment_method' => 'required|string',
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;

        // Generate reference number: EXP-YYYYMMDD-XXX
        $dateStr = date('Ymd', strtotime($validated['expense_date']));
        $count = Expense::where('tenant_id', $tenantId)
            ->whereDate('expense_date', $validated['expense_date'])
            ->count() + 1;
        $refNumber = 'EXP-' . $dateStr . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
 
        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('expenses', 'public');
        }

        $expense = Expense::create([
            'tenant_id' => $tenantId,
            'branch_id' => $validated['branch_id'],
            'expense_category_id' => $validated['expense_category_id'],
            'reference_number' => $refNumber,
            'amount' => $validated['amount'],
            'expense_date' => $validated['expense_date'],
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'],
            'attachment' => $attachmentPath,
            'created_by' => $userId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pengeluaran berhasil dicatat',
            'data' => $expense->load(['category', 'branch']),
        ], 201);
    }

    /**
     * Update Expense
     */
    public function update(Request $request, $id)
    {
        $expense = Expense::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'expense_category_id' => 'required|exists:expense_categories,id',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'payment_method' => 'required|string',
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($request->hasFile('attachment')) {
            // Delete old attachment if exists
            if ($expense->attachment) {
                Storage::disk('public')->delete($expense->attachment);
            }
            $validated['attachment'] = $request->file('attachment')->store('expenses', 'public');
        }

        $expense->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Catatan pengeluaran berhasil diperbarui',
            'data' => $expense->load(['category', 'branch']),
        ]);
    }

    /**
     * Delete Expense
     */
    public function destroy(Request $request, $id)
    {
        $expense = Expense::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        
        if ($expense->attachment) {
            Storage::disk('public')->delete($expense->attachment);
        }
        
        $expense->delete();
 
        return response()->json([
            'success' => true,
            'message' => 'Catatan pengeluaran berhasil dihapus',
        ]);
    }
}
