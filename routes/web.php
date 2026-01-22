<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'version' => '1.0.0',
        'status' => 'active',
        'message' => 'Welcome to Bengkel API. Access /api/v1 for API endpoints.',
        'documentation' => config('app.url') . '/docs'
    ]);
});
