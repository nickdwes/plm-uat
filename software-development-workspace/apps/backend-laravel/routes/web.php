<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Health check — used by load balancers and Docker HEALTHCHECK
Route::get('/health', function () {
    return response()->json([
        'status'  => 'ok',
        'version' => config('app.hrms_api_version', env('HRMS_API_VERSION', 'v1')),
    ]);
});

