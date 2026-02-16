<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Get all users with pagination
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $query = User::where('tenant_id', $tenantId)
            ->with(['roles', 'branches']);

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->has('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $users = $query->orderBy('name')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Create a new user
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:50',
            'role' => 'required|string|exists:roles,name',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
            'is_active' => 'boolean',
            'face_descriptor' => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'face_descriptor' => $validated['face_descriptor'] ?? null,
        ]);

        // Assign role
        $user->assignRole($validated['role']);

        // Attach branches
        if (!empty($validated['branch_ids'])) {
            $user->branches()->attach($validated['branch_ids']);
        }

        // Sync branch to linked employee if exists and only one branch is selected
        if ($user->employee && !empty($validated['branch_ids']) && count($validated['branch_ids']) === 1) {
            $user->employee->update(['branch_id' => $validated['branch_ids'][0]]);
        }

        // Sync face descriptor to linked employee if exists
        if (!empty($user->face_descriptor)) {
            $user->employee()->update(['face_descriptor' => $user->face_descriptor]);
        }

        $user->load(['roles', 'branches']);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    /**
     * Get user detail
     */
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $user = User::where('tenant_id', $tenantId)
            ->with(['roles', 'branches'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'phone' => 'nullable|string|max:50',
            'role' => 'required|string|exists:roles,name',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
            'is_active' => 'boolean',
            'face_descriptor' => 'nullable|string',
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'face_descriptor' => $validated['face_descriptor'] ?? $user->face_descriptor,
        ]);

        // Update password if provided
        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        // Sync role
        $user->syncRoles([$validated['role']]);

        // Sync branches
        if (isset($validated['branch_ids'])) {
            $user->branches()->sync($validated['branch_ids']);
        }

        // Sync branch to linked employee if exists and only one branch is selected
        if ($user->employee && !empty($validated['branch_ids']) && count($validated['branch_ids']) === 1) {
            $user->employee->update(['branch_id' => $validated['branch_ids'][0]]);
        }

        // Sync face descriptor to linked employee if exists
        if ($user->face_descriptor) {
            $user->employee()->update(['face_descriptor' => $user->face_descriptor]);
        }

        $user->load(['roles', 'branches']);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user,
        ]);
    }

    /**
     * Delete user (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        // Prevent deleting self
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete your own account',
            ], 400);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Assign roles to user
     */
    public function assignRoles(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,name',
        ]);

        $user->syncRoles($validated['roles']);

        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Roles assigned successfully',
            'data' => $user,
        ]);
    }

    /**
     * Get all roles
     */
    public function getRoles()
    {
        $roles = Role::all();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Toggle user active status
     */
    public function toggleStatus(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        $user = User::where('tenant_id', $tenantId)->findOrFail($id);

        $user->update(['is_active' => !$user->is_active]);

        return response()->json([
            'success' => true,
            'message' => $user->is_active ? 'User activated' : 'User deactivated',
            'data' => $user,
        ]);
    }
}
