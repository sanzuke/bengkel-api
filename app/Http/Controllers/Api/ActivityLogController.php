<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = Activity::with(['causer', 'subject'])
            ->latest();

        // Filter by subject type (model)
        if ($request->has('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        // Filter by subject ID
        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        // Filter by causer (user)
        if ($request->has('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }

        // Filter by event type
        if ($request->has('event')) {
            $query->where('event', $request->event);
        }

        // Date range filter
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $activities = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }

    public function show($id)
    {
        $activity = Activity::with(['causer', 'subject'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $activity,
        ]);
    }
}
