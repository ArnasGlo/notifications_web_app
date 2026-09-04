<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\NumberController as AdminNumberController;
use App\Http\Controllers\Admin\MessageCategoryController;
use App\Http\Controllers\NumberController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DelegateController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\InviteController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Auth::routes(['register' => false]);

Route::get('logout', function () {
    Auth::logout();
    return redirect('/login')->with('success', 'Successfully logged out.');
})->name('logout');

// ── Invite / Delegation ──────────────────────────────────────────────────────
Route::get('/invite/{token}', [InviteController::class, 'show'])->name('invite.show');
Route::post('/invite/{token}', [InviteController::class, 'accept'])->name('invite.accept')->middleware('auth');

// ── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/', fn() => redirect()->route('messages.index'));

    Route::resource('numbers', NumberController::class)->except(['show']);
    Route::resource('numbers.blocks', BlockController::class)->only(['index', 'create', 'store', 'destroy']);
    Route::resource('favorites', FavoriteController::class)->only(['index', 'store', 'destroy']);

    // Delegation management
    Route::get('numbers/{number}/delegates', [DelegateController::class, 'index'])->name('numbers.delegates.index');
    Route::delete('numbers/{number}/delegates/{delegate}', [DelegateController::class, 'destroy'])->name('numbers.delegates.destroy');

    // Messaging
    Route::get('messages', [ConversationController::class, 'index'])->name('messages.index');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::get('messages/compose', [MessageController::class, 'compose'])->name('messages.compose');
    Route::post('messages', [MessageController::class, 'store'])->name('messages.store');

    Route::get('numbers/{number}/messages', [ConversationController::class, 'forNumber'])->name('numbers.messages');

    Route::post('status', [App\Http\Controllers\StatusController::class, 'update'])->name('status.update');

    Route::get('/api/numbers/lookup', function () {
        $number = \App\Models\Number::where('number', request()->input('number'))
            ->where('status', 'active')
            ->first(['id', 'number']);
        return response()->json($number ?? (object)[]);
    });
});

// ── Admin routes ─────────────────────────────────────────────────────────────
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('users', AdminUserController::class);
    Route::resource('numbers', AdminNumberController::class);
    Route::resource('categories', MessageCategoryController::class);
    Route::resource('templates', App\Http\Controllers\Admin\MessageTemplateController::class);
});

Route::get('/admin', fn() => redirect()->route('admin.dashboard'));
Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
