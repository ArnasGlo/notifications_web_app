<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DelegateController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\NumberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::get('/invite/{token}', [InviteController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [AuthController::class, 'me']);

    Route::get('/numbers/search', [NumberController::class, 'lookup']);

    Route::get('/numbers', [NumberController::class, 'index']);
    Route::post('/numbers', [NumberController::class, 'store']);
    Route::patch('/numbers/{number}', [NumberController::class, 'update']);
    Route::delete('/numbers/{number}', [NumberController::class, 'destroy']);

    Route::get('/numbers/{number}/delegates', [DelegateController::class, 'index']);
    Route::delete('/numbers/{number}/delegates/{delegate}', [DelegateController::class, 'destroy']);

    Route::post('/invite/{token}/accept', [InviteController::class, 'accept']);
});
