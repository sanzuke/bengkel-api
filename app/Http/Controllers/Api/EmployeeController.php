<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $query = Employee::where('tenant_id', $tenantId)->with('user:id,name,email,profile_photo_url');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%")
                  ->orWhere('department', 'like', "%{$search}%")
                  ->orWhere('position', 'like', "%{$search}%");
            });
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $employees = $query->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $employees
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nik' => ['nullable', 'string', 'max:50', Rule::unique('employees')->where('tenant_id', $request->user()->tenant_id)],
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'join_date' => 'nullable|date',
            'status' => 'required|in:active,inactive,terminated',
            'user_id' => ['nullable', 'exists:users,id', Rule::unique('employees')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $data = $request->all();
        $data['tenant_id'] = $request->user()->tenant_id;

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('employee_photos', 'public');
            $data['photo_url'] = Storage::url($path);
        }

        $employee = Employee::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully',
            'data' => $employee
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $employee = Employee::with('user:id,name,email,profile_photo_url')->findOrFail($id);
        
        // Ensure tenant isolation
        if ($employee->tenant_id !== request()->user()->tenant_id) {
            abort(403);
        }

        return response()->json([
            'success' => true,
            'data' => $employee
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $employee = Employee::findOrFail($id);
        
        // Ensure tenant isolation
        if ($employee->tenant_id !== $request->user()->tenant_id) {
            abort(403);
        }

        $request->validate([
            'nik' => ['nullable', 'string', 'max:50', Rule::unique('employees')->ignore($id)->where('tenant_id', $request->user()->tenant_id)],
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'join_date' => 'nullable|date',
            'status' => 'required|in:active,inactive,terminated',
            'user_id' => ['nullable', 'exists:users,id', Rule::unique('employees')->ignore($id)->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $data = $request->all();

        if ($request->hasFile('photo')) {
            if ($employee->photo_url) {
                $oldPath = str_replace('/storage/', '', $employee->photo_url);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('photo')->store('employee_photos', 'public');
            $data['photo_url'] = Storage::url($path);
        }

        $employee->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully',
            'data' => $employee
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $employee = Employee::findOrFail($id);
        
        // Ensure tenant isolation
        if ($employee->tenant_id !== request()->user()->tenant_id) {
            abort(403);
        }

        if ($employee->photo_url) {
            $path = str_replace('/storage/', '', $employee->photo_url);
            Storage::disk('public')->delete($path);
        }

        $employee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Employee deleted successfully'
        ]);
    }
    
    /**
     * Get users available for linking (not yet linked to an employee)
     */
    public function getAvailableUsers()
    {
        $tenantId = request()->user()->tenant_id;
        
        // Users who are NOT in employees table OR are the current linked user (for edit)
        // For simplicity in list: Users not in employees table for this tenant
        
        $linkedUserIds = Employee::where('tenant_id', $tenantId)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->toArray();
            
        $users = User::where('tenant_id', $tenantId)
            ->whereNotIn('id', $linkedUserIds)
            ->select('id', 'name', 'email')
            ->get();
            
        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }
}
