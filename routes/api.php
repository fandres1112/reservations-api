<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ReservationController;

Route::get('/reservations', [ReservationController::class, 'index']);
Route::post('/reservations', [ReservationController::class, 'store']);
Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel']);
