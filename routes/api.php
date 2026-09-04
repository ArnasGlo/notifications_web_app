<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DelegateController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NumberController;
use App\Http\Controllers\Api\StatusController;
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

    Route::get('/numbers/{number}/blocks', [BlockController::class, 'index']);
    Route::post('/numbers/{number}/blocks', [BlockController::class, 'store']);
    Route::delete('/numbers/{number}/blocks/{block}', [BlockController::class, 'destroy']);

    Route::get('/numbers/{number}/messages', [MessageController::class, 'numberInbox']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);

    Route::get('/messages/compose-data', [MessageController::class, 'composeData']);
    Route::get('/messages', [MessageController::class, 'index']);
    Route::post('/messages', [MessageController::class, 'store']);
    Route::get('/messages/{message}', [MessageController::class, 'show']);
    Route::post('/messages/{message}/reply', [MessageController::class, 'reply']);

    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{favorite}', [FavoriteController::class, 'destroy']);

    Route::patch('/status', [StatusController::class, 'update']);

    Route::post('/invite/{token}/accept', [InviteController::class, 'accept']);
});
