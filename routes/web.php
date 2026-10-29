<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealershipController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesController;
use App\Http\Middleware\ActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::middleware(['auth', 'auth.session', ActiveUser::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::resource('settings/dealerships', DealershipController::class)->except(['show', 'destroy']);
    Route::post('/dealership-context', [DealershipController::class, 'context'])->name('dealership-context');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/concesionarios/responsible-users', [ClientController::class, 'responsibleUsers'])->name('clients.responsible-users');
    Route::resource('concesionarios', ClientController::class)->parameters(['concesionarios' => 'client'])->names('clients')->except('destroy');
    Route::get('/clients', fn () => redirect()->route('clients.index'));
    Route::get('/clients/create', fn () => redirect()->route('clients.create'));
    Route::get('/clients/{client}/edit', fn (string $client) => redirect()->route('clients.edit', $client));
    Route::get('/clients/{client}', fn (string $client) => redirect()->route('clients.show', $client));
    Route::get('/orders', [SalesController::class, 'orders'])->name('orders.index');
    Route::get('/products/options', [ProductController::class, 'options'])->name('products.options');
    Route::resource('products', ProductController::class)->except('show');
    Route::get('/receipts/account', [ReceiptController::class, 'account'])->name('receipts.account');
    Route::resource('receipts', ReceiptController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/receipts/{receipt}/apply', [ReceiptController::class, 'apply'])->name('receipts.apply');
    Route::post('/receipts/{receipt}/void', [ReceiptController::class, 'void'])->name('receipts.void');
    Route::get('/orders/create', [SalesController::class, 'create'])->name('orders.create');
    Route::get('/orders/client-options', [SalesController::class, 'clientOptions'])->name('orders.client-options');
    Route::post('/orders', [SalesController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [SalesController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/payments', [SalesController::class, 'pay'])->name('orders.pay');
    Route::post('/payments/{payment}/void', [SalesController::class, 'void'])->name('payments.void');
    Route::post('/orders/{order}/cancel', [SalesController::class, 'cancel'])->name('orders.cancel');
    Route::get('/collections', [DashboardController::class, 'collections'])->name('collections');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/users/create', [AdminController::class, 'createUser'])->name('users.create');
    Route::get('/users/{user}/edit', [AdminController::class, 'editUser'])->name('users.edit');
    Route::post('/users', [AdminController::class, 'saveUser'])->name('users.store');
    Route::put('/users/{user}', [AdminController::class, 'saveUser'])->name('users.update');
    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
    Route::resource('settings/payment-methods', PaymentMethodController::class)->except('show');
});
