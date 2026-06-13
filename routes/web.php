<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name', 'Reservations API'),
        'status' => 'active',
        'version' => '1.0.0',
        'message' => 'Bienvenido al Sistema de Reservas API.'
    ]);
});
