<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('organizations', [OrganizationController::class, 'index'])->middleware('permission:organizaciones.ver');
        Route::get('organizations/{id}', [OrganizationController::class, 'show'])->middleware('permission:organizaciones.ver');
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('permission:organizaciones.crear');
        Route::put('organizations/{id}', [OrganizationController::class, 'update'])->middleware('permission:organizaciones.editar');
        Route::delete('organizations/{id}', [OrganizationController::class, 'destroy'])->middleware('permission:organizaciones.eliminar');
    });
});
