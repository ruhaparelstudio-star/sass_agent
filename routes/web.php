<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'app',
    ]);
});

Route::get('/health/db', function () {
    DB::connection()->getPdo();

    return response()->json([
        'status' => 'ok',
        'service' => 'db',
    ]);
});

Route::get('/health/redis', function () {
    Redis::connection()->ping();

    return response()->json([
        'status' => 'ok',
        'service' => 'redis',
    ]);
});
