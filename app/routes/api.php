<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AvatarAdminController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\ProfileAdminController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Imágenes de avatar: URL firmada (un <img> no puede enviar el token)
    Route::get('media/avatars/{avatar}', [MediaController::class, 'avatar'])->name('media.avatar')->middleware('signed:relative');
    Route::get('media/users/{user}/avatar', [MediaController::class, 'userAvatar'])->name('media.user-avatar')->middleware('signed:relative');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('me/password', [AuthController::class, 'changePassword']);

        Route::get('profiles', [UserProfileController::class, 'profiles'])->middleware('permission:perfiles.ver');
        Route::get('permissions', [ProfileAdminController::class, 'permissions'])->middleware('permission:perfiles.ver');
        Route::post('profiles', [ProfileAdminController::class, 'store'])->middleware('permission:perfiles.crear');
        Route::put('profiles/{id}', [ProfileAdminController::class, 'update'])->middleware('permission:perfiles.editar');
        Route::delete('profiles/{id}', [ProfileAdminController::class, 'destroy'])->middleware('permission:perfiles.eliminar');

        // Mi avatar (cualquier usuario autenticado)
        Route::get('me/avatar', [AvatarController::class, 'options']);
        Route::put('me/avatar', [AvatarController::class, 'select']);
        Route::post('me/avatar', [AvatarController::class, 'upload']);
        Route::delete('me/avatar', [AvatarController::class, 'remove']);

        // Galerías de avatares y su asignación a empresas
        Route::get('avatar-collections', [AvatarAdminController::class, 'index'])->middleware('permission:avatares.ver');
        Route::post('avatar-collections', [AvatarAdminController::class, 'store'])->middleware('permission:avatares.crear');
        Route::put('avatar-collections/{id}', [AvatarAdminController::class, 'update'])->middleware('permission:avatares.editar');
        Route::delete('avatar-collections/{id}', [AvatarAdminController::class, 'destroy'])->middleware('permission:avatares.eliminar');
        Route::post('avatar-collections/{id}/avatars', [AvatarAdminController::class, 'addAvatars'])->middleware('permission:avatares.crear');
        Route::put('avatars/{id}', [AvatarAdminController::class, 'updateAvatar'])->middleware('permission:avatares.editar');
        Route::delete('avatars/{id}', [AvatarAdminController::class, 'destroyAvatar'])->middleware('permission:avatares.eliminar');
        Route::get('users/{userId}/profiles', [UserProfileController::class, 'index'])->middleware('permission:usuarios.ver');
        Route::post('users/{userId}/profiles', [UserProfileController::class, 'assign'])->middleware('permission:usuarios.editar');
        Route::delete('users/{userId}/profiles/{assignmentId}', [UserProfileController::class, 'revoke'])->middleware('permission:usuarios.editar');

        Route::get('organizations', [OrganizationController::class, 'index'])->middleware('permission:organizaciones.ver');
        Route::get('organizations/{id}', [OrganizationController::class, 'show'])->middleware('permission:organizaciones.ver');
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('permission:organizaciones.crear');
        Route::put('organizations/{id}', [OrganizationController::class, 'update'])->middleware('permission:organizaciones.editar');
        Route::delete('organizations/{id}', [OrganizationController::class, 'destroy'])->middleware('permission:organizaciones.eliminar');

        // Importador universal (Excel/CSV): subir -> mapear -> simular -> cargar
        Route::get('imports/targets', [ImportController::class, 'targets'])->middleware('permission:importaciones.ver');
        Route::get('imports/targets/{target}/template', [ImportController::class, 'template'])->middleware('permission:importaciones.ver');
        Route::get('imports', [ImportController::class, 'index'])->middleware('permission:importaciones.ver');
        Route::get('imports/{id}', [ImportController::class, 'show'])->middleware('permission:importaciones.ver');
        Route::post('imports', [ImportController::class, 'upload'])->middleware('permission:importaciones.crear');
        Route::post('imports/{id}/run', [ImportController::class, 'run'])->middleware('permission:importaciones.crear');
        Route::get('import-templates', [ImportController::class, 'templates'])->middleware('permission:importaciones.ver');
        Route::delete('import-templates/{id}', [ImportController::class, 'destroyTemplate'])->middleware('permission:importaciones.crear');

        // Recursos acotados por tenant y alcance: ruta => [controlador, prefijo de permisos]
        $resources = [
            'branches' => [BranchController::class, 'sucursales'],
            'areas' => [AreaController::class, 'areas'],
            'cost-centers' => [CostCenterController::class, 'centros_costo'],
            'people' => [PersonController::class, 'personas'],
            'projects' => [ProjectController::class, 'proyectos'],
            'users' => [UserController::class, 'usuarios'],
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
