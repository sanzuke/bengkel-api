<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = Category::where('tenant_id', $tenantId)
            ->withCount('products');

        // Filter by search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Filter by parent
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        // Filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Include parent and children relationships
        $query->with(['parent:id,name', 'children:id,parent_id,name']);

        $categories = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $tenantId = $request->user()->tenant_id;

        // Check if parent belongs to same tenant
        if (!empty($validated['parent_id'])) {
            $parent = Category::where('id', $validated['parent_id'])
                ->where('tenant_id', $tenantId)
                ->first();
            if (!$parent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent category not found',
                ], 404);
            }
        }

        $category = Category::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'parent_id' => $validated['parent_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category->load('parent:id,name'),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $category = Category::where('tenant_id', $tenantId)
            ->with(['parent:id,name', 'children:id,parent_id,name', 'products:id,category_id,name,sku'])
            ->withCount('products')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    public function update(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'parent_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        // Prevent setting self as parent
        if (!empty($validated['parent_id']) && $validated['parent_id'] == $id) {
            return response()->json([
                'success' => false,
                'message' => 'Category cannot be its own parent',
            ], 422);
        }

        // Check if parent belongs to same tenant
        if (!empty($validated['parent_id'])) {
            $parent = Category::where('id', $validated['parent_id'])
                ->where('tenant_id', $tenantId)
                ->first();
            if (!$parent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent category not found',
                ], 404);
            }
        }

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category->fresh()->load('parent:id,name'),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $category = Category::where('tenant_id', $tenantId)
            ->withCount(['products', 'children'])
            ->findOrFail($id);

        // Prevent deletion if has products
        if ($category->products_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete category with {$category->products_count} products. Move products first.",
            ], 422);
        }

        // Prevent deletion if has children
        if ($category->children_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete category with {$category->children_count} sub-categories. Delete sub-categories first.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Get categories as tree structure for dropdown
     */
    public function tree(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $categories = Category::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->where('is_active', true)->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}
