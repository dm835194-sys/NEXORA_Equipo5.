<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});
Route::middleware('auth')->group(function () {
    Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/inventario', [PortalController::class, 'inventory'])->name('inventory');
    Route::get('/activos/{asset}/qr', [PortalController::class, 'assetQr'])->name('assets.qr');
    Route::patch('/activos/{asset}', [PortalController::class, 'assetUpdate'])->name('assets.update');
    Route::get('/practicas', [PortalController::class, 'practices'])->name('practices');
    Route::post('/practicas', [PortalController::class, 'practiceStore'])->name('practices.store');
    Route::get('/practicas/{practice}/disponibilidad', [ReservationController::class, 'availability'])->name('practices.availability');
    Route::get('/avisos', [PortalController::class, 'notices'])->name('notices');
    Route::get('/reglamento', [PortalController::class, 'rules'])->name('rules');
    Route::get('/indicadores', [PortalController::class, 'metrics'])->name('metrics');
    Route::get('/simulacion', [PortalController::class, 'simulation'])->name('simulation');
    Route::get('/reservas', [ReservationController::class, 'index'])->name('reservations.index');
    Route::get('/reservas/nueva', [ReservationController::class, 'create'])->name('reservations.create');
    Route::post('/reservas', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('/reservas/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
    Route::post('/reservas/{reservation}/qr', [ReservationController::class, 'qr'])->middleware('throttle:20,1')->name('reservations.qr');
    Route::post('/reservas/{reservation}/llegada', [ReservationController::class, 'arrive'])->name('reservations.arrive');
    Route::post('/reservas/{reservation}/cancelar', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::get('/ventanilla', [OperationController::class, 'index'])->name('operations.index');
    Route::post('/ventanilla/leer', [OperationController::class, 'scan'])->name('operations.scan');
    Route::get('/q/{token}', [OperationController::class, 'fromQr'])->name('operations.qr');
    Route::post('/ventanilla/{reservation}/preparar', [OperationController::class, 'prepare'])->name('operations.prepare');
    Route::get('/ventanilla/{reservation}/entregar', [OperationController::class, 'delivery'])->name('operations.delivery');
    Route::post('/ventanilla/{reservation}/entregar', [OperationController::class, 'deliver'])->name('operations.deliver');
    Route::post('/devoluciones/{loan}', [OperationController::class, 'receive'])->name('operations.receive');
    Route::post('/revisiones/{loan}', [OperationController::class, 'inspect'])->name('operations.inspect');
    Route::get('/incidencias', [OperationController::class, 'incidents'])->name('incidents');
    Route::post('/incidencias', [OperationController::class, 'incidentStore'])->name('incidents.store');
    Route::post('/incidencias/{incident}/resolver', [OperationController::class, 'resolve'])->name('incidents.resolve');
    Route::get('/contingencia', [OperationController::class, 'contingency'])->name('contingency');
    Route::get('/contingencia/respaldo', [OperationController::class, 'export'])->name('contingency.export');
    Route::post('/contingencia/conciliar',[OperationController::class, 'import'])->name('contingency.import');
});
