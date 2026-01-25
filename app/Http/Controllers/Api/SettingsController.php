<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    /**
     * Get the current tenant settings.
     */
    public function index(Request $request)
    {
        $tenant = $request->user()->tenant;

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $tenant->name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'address' => $tenant->address,
                'logo_url' => $tenant->logo ? url('storage/' . $tenant->logo) : null,
                'settings' => $tenant->settings,
            ],
        ]);
    }

    /**
     * Update the tenant settings.
     */
    public function update(Request $request)
    {
        try {
            $tenant = $request->user()->tenant;

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:50',
                'address' => 'required|string',
                'logo' => 'nullable|image|max:2048', // Max 2MB
            ]);

            // Handle logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($tenant->logo) {
                    Storage::disk('public')->delete($tenant->logo);
                }

                $path = $request->file('logo')->store('tenants/logos', 'public');
                $tenant->logo = $path;
            }

            $tenant->name = $validated['name'];
            $tenant->email = $validated['email'];
            $tenant->phone = $validated['phone'];
            $tenant->address = $validated['address'];
            $tenant->save();

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
                'data' => [
                    'name' => $tenant->name,
                    'email' => $tenant->email,
                    'phone' => $tenant->phone,
                    'address' => $tenant->address,
                    'logo_url' => $tenant->logo ? url('storage/' . $tenant->logo) : null,
                    'settings' => $tenant->settings,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Settings update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings: ' . $e->getMessage(),
            ], 500);
        }
    }
}
