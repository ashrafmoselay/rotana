<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExcelController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::middleware(['auth', 'active'])->group(function () {
    Route::view('/', 'app');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/media/{media}', [MediaController::class, 'show'])->name('media.show');
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);
    Route::prefix('api')->group(function () {
        Route::get('me', fn () => ['user' => auth()->user()->only('id', 'name', 'email', 'all_branches'), 'permissions' => auth()->user()->getAllPermissions()->pluck('name')]);
        Route::get('lookups', [MasterController::class, 'lookups']);
        Route::get('dashboard', DashboardController::class);
        Route::get('search', SearchController::class)->middleware('throttle:30,1');
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::put('orders/{order}', [OrderController::class, 'update']);
        Route::post('orders/{order}/actions/{action}', [OrderController::class, 'action']);
        foreach (['receipt', 'invoice', 'payment'] as $method) {
            Route::post('orders/{order}/'.$method, [OrderController::class, $method]);
        }
        Route::post('orders/{order}/media', [MediaController::class, 'store']);
        Route::get('inventory/balances', [InventoryController::class, 'balances']);
        Route::get('inventory/movements', [InventoryController::class, 'movements']);
        Route::post('inventory/movements', [InventoryController::class, 'store']);
        Route::get('cards', [CardController::class, 'index']);
        Route::post('cards', [CardController::class, 'store']);
        Route::get('cards/summary', [CardController::class, 'summary']);
        Route::put('cards/{card}', [CardController::class, 'edit']);
        Route::patch('cards/{card}', [CardController::class, 'update']);
        Route::delete('cards/{card}', [CardController::class, 'destroy']);
        Route::get('masters/{kind}', [MasterController::class, 'index']);
        Route::post('masters/{kind}', [MasterController::class, 'store']);
        Route::put('masters/{kind}/{id}', [MasterController::class, 'update']);
        Route::get('admin/users', [AdminController::class, 'users']);
        Route::post('admin/users', [AdminController::class, 'userSave']);
        Route::put('admin/users/{user}', [AdminController::class, 'userSave']);
        Route::get('admin/roles', [AdminController::class, 'roles']);
        Route::post('admin/roles', [AdminController::class, 'roleSave']);
        Route::put('admin/roles/{role}', [AdminController::class, 'roleSave']);
        Route::get('admin/activity', [AdminController::class, 'activity']);
        Route::get('excel/template/{kind}', [ExcelController::class, 'template']);
        Route::post('excel/import/{kind}',[ExcelController::class, 'import']);
        Route::get('excel/export/{kind}',[ExcelController::class, 'export']);
    });
});
