<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\ProjectController;
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

        // Recursos acotados por tenant y alcance: ruta => [controlador, prefijo de permisos]
        $resources = [
            'branches' => [BranchController::class, 'sucursales'],
            'areas' => [AreaController::class, 'areas'],
            'cost-centers' => [CostCenterController::class, 'centros_costo'],
            'people' => [PersonController::class, 'personas'],
            'projects' => [ProjectController::class, 'proyectos'],
        ];
        foreach ($resources as $path => [$controller, $perm]) {
            Route::get($path, [$controller, 'index'])->middleware("permission:$perm.ver");
            Route::get("$path/{id}", [$controller, 'show'])->middleware("permission:$perm.ver");
            Route::post($path, [$controller, 'store'])->middleware("permission:$perm.crear");
            Route::put("$path/{id}", [$controller, 'update'])->middleware("permission:$perm.editar");
            Route::delete("$path/{id}", [$controller, 'destroy'])->middleware("permission:$perm.eliminar");
        }
    });
});
