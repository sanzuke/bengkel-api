<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    public function show(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;
        
        $sale = Sale::where('tenant_id', $tenantId)
            ->with(['items.product', 'customer', 'branch', 'creator', 'tenant'])
            ->findOrFail($id);

        $html = view('receipts.thermal', compact('sale'))->render();

        return response()->json([
            'success' => true,
            'html' => $html
        ]);
    }
}
