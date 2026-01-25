<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Branch;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AttendanceController extends Controller
{
    /**
     * Get attendance history
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $query = Attendance::where('tenant_id', $tenantId)
            ->with(['user:id,name,email', 'employee:id,name,position,photo_url,face_descriptor', 'branch:id,name']);

        // Role-based filtering
        $user = $request->user();
        if (!$user->hasRole(['admin', 'manager', 'owner'])) {
            // Regular user: only own attendance
            $query->where('user_id', $user->id);
        } else {
            // Admin/Manager: Can filter by user or branch
            
            // Restrict Branch Admin/Manager to their assigned branch
            if (!$user->hasRole('owner')) {
                $employee = $user->employee;
                if ($employee && $employee->branch_id) {
                    $query->where('branch_id', $employee->branch_id);
                }
            }

            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->has('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }
            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }
        }

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }
        
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }

        $attendances = $query->orderBy('date', 'desc')->orderBy('clock_in', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $attendances
        ]);
    }

    /**
     * Get attendance summary for dashboard (Admin only)
     */
    public function summary(Request $request)
    {
        if (!$request->user()->hasRole(['admin', 'manager', 'owner'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $tenantId = $request->user()->tenant_id;
        $date = $request->date ?? Carbon::today()->format('Y-m-d');
        $branchId = $request->branch_id;

        // Base queries
        $empQuery = Employee::where('tenant_id', $tenantId)->where('status', 'active');
        $attQuery = Attendance::where('tenant_id', $tenantId)->where('date', $date);

        if ($branchId) {
            $empQuery->where('branch_id', $branchId);
            $attQuery->where('branch_id', $branchId);
        }

        $totalEmployees = $empQuery->count();
        
        // Count by status
        $stats = (clone $attQuery)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $presentCount = $stats['present'] ?? 0;
        $sickCount = $stats['sick'] ?? 0;
        $leaveCount = $stats['leave'] ?? 0;
        $alphaCount = $stats['alpha'] ?? 0;

        $totalAttendance = array_sum($stats);

        // Late logic (only for present status)
        $late = (clone $attQuery)
            ->where('status', 'present')
            ->whereTime('clock_in', '>', '09:00:00')
            ->count();

        // Absent (No record)
        $noRecord = max(0, $totalEmployees - $totalAttendance);
        
        // Total Absent (Alpha + No Record)
        $absentTotal = $alphaCount + $noRecord;

        // Group by Branch if Owner/Admin requests it (and no specific branch selected)
        $byBranch = [];
        if (!$branchId && $request->has('group_by_branch')) {
            $branches = Branch::where('tenant_id', $tenantId)->get();
            foreach ($branches as $branch) {
                $branchEmpCount = Employee::where('branch_id', $branch->id)->where('status', 'active')->count();
                $branchAttCount = Attendance::where('branch_id', $branch->id)->where('date', $date)->count();
                
                $byBranch[] = [
                    'branch' => $branch->name,
                    'total' => $branchEmpCount,
                    'present' => $branchAttCount,
                    'absent' => max(0, $branchEmpCount - $branchAttCount)
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'total_employees' => $totalEmployees,
                'present' => $presentCount,
                'late' => $late,
                'absent' => $absentTotal,
                'sick' => $sickCount,
                'leave' => $leaveCount,
                'by_branch' => $byBranch
            ]
        ]);
    }

    /**
     * Get current user's attendance status for today
     */
    public function todayStatus(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;
        $today = Carbon::today()->format('Y-m-d');

        $attendance = Attendance::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('date', $today)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $attendance
        ]);
    }

    /**
     * Self Clock In (Authenticated User)
     */
    public function clockIn(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $userId = $request->user()->id;
        $today = Carbon::today()->format('Y-m-d');

        // Check if already clocked in
        $attendance = Attendance::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('date', $today)
            ->first();

        if ($attendance) {
            return response()->json(['success' => false, 'message' => 'Already clocked in today'], 400);
        }

        // Find associated Employee record
        $employee = Employee::where('user_id', $userId)->first();

        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('attendance-photos', 'public');
            $photoUrl = Storage::url($path);
        }

        $attendance = Attendance::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'employee_id' => $employee ? $employee->id : null,
            'branch_id' => $employee ? $employee->branch_id : null, // Inherit branch from employee record
            'date' => $today,
            'clock_in' => Carbon::now(),
            'status' => 'present',
            'notes' => $request->notes,
            'location_lat' => $request->location_lat,
            'location_long' => $request->location_long,
            'photo_url' => $photoUrl
        ]);

        return response()->json(['success' => true, 'data' => $attendance]);
    }

    /**
     * Manual Attendance Entry (Admin/Manager)
     */
    public function storeManual(Request $request)
    {
        if (!$request->user()->hasRole(['admin', 'manager', 'owner'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date' => 'required|date',
            'clock_in' => 'required|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i|after:clock_in',
            'status' => 'required|in:present,sick,leave,alpha',
            'notes' => 'nullable|string'
        ]);

        $employee = Employee::findOrFail($request->employee_id);
        
        // Ensure employee belongs to same tenant
        if ($employee->tenant_id != $request->user()->tenant_id) {
            return response()->json(['success' => false, 'message' => 'Employee not found'], 404);
        }

        // Create or Update
        $attendance = Attendance::updateOrCreate(
            [
                'tenant_id' => $request->user()->tenant_id,
                'employee_id' => $employee->id,
                'date' => $request->date
            ],
            [
                'user_id' => $employee->user_id,
                'branch_id' => $employee->branch_id,
                'clock_in' => $request->date . ' ' . $request->clock_in,
                'clock_out' => $request->clock_out ? $request->date . ' ' . $request->clock_out : null,
                'status' => $request->status,
                'notes' => $request->notes
            ]
        );

        return response()->json(['success' => true, 'message' => 'Attendance saved successfully', 'data' => $attendance]);
    }

    // --- KIOSK METHODS ---

    /**
     * Public: Get Employees for Kiosk (by Branch Code or Tenant)
     * Requires a shared secret or just tenant/branch identification?
     * For simplicity, we'll assume the frontend sends tenant_slug or branch_code.
     */
    public function kioskEmployees(Request $request)
    {
        // Simple security: Require a valid branch_code or just list all for tenant?
        // User asked "tanpa harus login".
        // Usually we'd pass ?branch_code=XYZ or ?tenant_slug=abc
        
        $query = Employee::query()->where('status', 'active');

        if ($request->has('branch_code')) {
            $query->whereHas('branch', function($q) use ($request) {
                $q->where('code', $request->branch_code);
            });
        } elseif ($request->has('tenant_slug')) {
             $query->whereHas('tenant', function($q) use ($request) {
                $q->where('slug', $request->tenant_slug);
            });
        } else {
            return response()->json(['success' => false, 'message' => 'Branch code or Tenant slug required'], 400);
        }

        $employees = $query->select('id', 'name', 'position', 'photo_url', 'face_descriptor')->get();

        return response()->json(['success' => true, 'data' => $employees]);
    }

    /**
     * Admin: Register Employee Face
     */
    public function registerFace(Request $request)
    {
        if (!$request->user()->hasRole(['admin', 'manager', 'owner'])) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'face_descriptor' => 'required', // This will be a JSON string of the 128 float vector
        ]);

        $employee = Employee::findOrFail($request->employee_id);
        
        // Ensure same tenant
        if ($employee->tenant_id != $request->user()->tenant_id) {
            return response()->json(['success' => false, 'message' => 'Employee not found'], 404);
        }

        $employee->update([
            'face_descriptor' => is_array($request->face_descriptor) 
                ? json_encode($request->face_descriptor) 
                : $request->face_descriptor
        ]);

        return response()->json(['success' => true, 'message' => 'Face registered successfully']);
    }

    /**
     * Public: Kiosk Clock In
     */
    public function kioskClockIn(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'pin_code' => 'nullable|string', 
            'photo' => 'nullable|image|max:5120', // Photo is optional if face matched, but recommended
            'is_face_matched' => 'nullable|boolean'
        ]);

        $employee = Employee::findOrFail($request->employee_id);

        // Verify PIN if set
        if ($employee->pin_code && $request->pin_code !== $employee->pin_code) {
             return response()->json(['success' => false, 'message' => 'Invalid PIN Code'], 401);
        }

        $today = Carbon::today()->format('Y-m-d');
        
        // Check if already clocked in
        $existing = Attendance::where('employee_id', $employee->id)
            ->where('date', $today)
            ->first();
            
        if ($existing && $existing->clock_out) {
             return response()->json(['success' => false, 'message' => 'Already completed attendance for today'], 400);
        }

        // Upload photo
        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('attendance-photos', 'public');
            $photoUrl = Storage::url($path);
        }

        if ($existing) {
            // Already clocked out?
            if ($existing->clock_out) {
                return response()->json(['success' => false, 'message' => 'Already completed attendance for today'], 400);
            }

            // Cooldown check: Prevent Clock Out if within 4 hours of Clock In
            $clockInTime = Carbon::parse($existing->clock_in);
            $hoursSinceIn = $clockInTime->diffInHours(Carbon::now());

            if ($hoursSinceIn < 4) {
                return response()->json([
                    'success' => true, 
                    'message' => 'Sudah Masuk @ ' . $clockInTime->format('H:i'), 
                    'type' => 'info',
                    'sub_message' => 'Belum masuk jam pulang (Min. 4 jam)'
                ]);
            }

            // Allow Clock Out
            $existing->update([
                'clock_out' => Carbon::now(),
            ]);
            return response()->json(['success' => true, 'message' => 'Clock Out Successful', 'type' => 'out']);
        } else {
            // Clock In
            Attendance::create([
                'tenant_id' => $employee->tenant_id,
                'branch_id' => $employee->branch_id,
                'employee_id' => $employee->id,
                'user_id' => $employee->user_id,
                'date' => $today,
                'clock_in' => Carbon::now(),
                'status' => 'present',
                'photo_url' => $photoUrl,
                'notes' => 'Kiosk Attendance'
            ]);
            return response()->json(['success' => true, 'message' => 'Clock In Successful', 'type' => 'in']);
        }
    }
}
