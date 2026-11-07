<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AreaController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AvatarAdminController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\ProfileAdminController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\FieldAuditController;
use App\Http\Controllers\Api\V1\FieldAuditFindingController;
use App\Http\Controllers\Api\V1\FieldAuditTemplateController;
use App\Http\Controllers\Api\V1\HoldingController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/forgot-password', [\App\Http\Controllers\Api\V1\PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('auth/reset-password', [\App\Http\Controllers\Api\V1\PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
    Route::post('auth/mfa', [AuthController::class, 'verifyMfa'])->middleware('throttle:20,1');

    // Imágenes de avatar: URL firmada (un <img> no puede enviar el token)
    Route::get('media/avatars/{avatar}', [MediaController::class, 'avatar'])->name('media.avatar')->middleware('signed:relative');
    Route::get('media/audit-evidence/{evidence}', [MediaController::class, 'auditEvidence'])->name('media.audit-evidence')->middleware('signed:relative');
    Route::get('media/sellout/products/{product}', [MediaController::class, 'selloutProduct'])->name('media.sellout-product')->middleware('signed:relative');
    Route::get('media/sellout/photos/{photo}', [MediaController::class, 'selloutPhoto'])->name('media.sellout-photo')->middleware('signed:relative');
    Route::get('media/sellout/responses/{req}', [MediaController::class, 'selloutResponse'])->name('media.sellout-response')->middleware('signed:relative');
    Route::get('media/logos/{setting}', [MediaController::class, 'logo'])->name('media.logo')->middleware('signed:relative');
    Route::get('media/users/{user}/avatar', [MediaController::class, 'userAvatar'])->name('media.user-avatar')->middleware('signed:relative');

    Route::middleware(['auth:sanctum', 'active', 'mfa'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('me/password', [AuthController::class, 'changePassword']);

        Route::get('profiles', [UserProfileController::class, 'profiles'])->middleware('permission:perfiles.ver');
        Route::get('permissions', [ProfileAdminController::class, 'permissions'])->middleware('permission:perfiles.ver');
        Route::post('profiles', [ProfileAdminController::class, 'store'])->middleware('permission:perfiles.crear');
        Route::put('profiles/{id}', [ProfileAdminController::class, 'update'])->middleware('permission:perfiles.editar');
        Route::delete('profiles/{id}', [ProfileAdminController::class, 'destroy'])->middleware('permission:perfiles.eliminar');

        // Verificación en dos pasos (propia)
        Route::post('me/mfa/setup', [MfaController::class, 'setup']);
        Route::post('me/mfa/confirm', [MfaController::class, 'confirm']);
        Route::post('me/mfa/recovery-codes', [MfaController::class, 'recoveryCodes']);
        Route::delete('me/mfa', [MfaController::class, 'disable']);

        // Mi avatar (cualquier usuario autenticado)
        Route::get('me/avatar', [AvatarController::class, 'options']);
        Route::put('me/avatar', [AvatarController::class, 'select']);
        Route::post('me/avatar', [AvatarController::class, 'upload']);
        Route::delete('me/avatar', [AvatarController::class, 'remove']);

        // Configuración por tenant / organización
        Route::get('settings', [SettingsController::class, 'show'])->middleware('permission:configuracion.ver');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:configuracion.editar');
        Route::post('settings/logo', [SettingsController::class, 'uploadLogo'])->middleware('permission:configuracion.editar');
        Route::delete('settings/logo', [SettingsController::class, 'deleteLogo'])->middleware('permission:configuracion.editar');

        // Auditoría en terreno: plantillas, auditorías, hallazgos
        Route::get('audit-templates', [FieldAuditTemplateController::class, 'index'])->middleware('permission:plantillas_auditoria.ver');
        Route::post('audit-templates', [FieldAuditTemplateController::class, 'store'])->middleware('permission:plantillas_auditoria.crear');
        Route::get('audit-templates/sample', [FieldAuditTemplateController::class, 'sample'])->middleware('permission:plantillas_auditoria.ver');
        Route::post('audit-templates/import', [FieldAuditTemplateController::class, 'import'])->middleware('permission:plantillas_auditoria.crear');
        Route::get('audit-templates/{id}/export', [FieldAuditTemplateController::class, 'export'])->middleware('permission:plantillas_auditoria.ver');
        Route::get('audit-templates/{id}', [FieldAuditTemplateController::class, 'show'])->middleware('permission:plantillas_auditoria.ver');
        Route::put('audit-templates/{id}', [FieldAuditTemplateController::class, 'update'])->middleware('permission:plantillas_auditoria.editar');
        Route::delete('audit-templates/{id}', [FieldAuditTemplateController::class, 'destroy'])->middleware('permission:plantillas_auditoria.eliminar');
        Route::post('audit-templates/{id}/publish', [FieldAuditTemplateController::class, 'publish'])->middleware('permission:plantillas_auditoria.editar');
        Route::post('audit-templates/{id}/archive', [FieldAuditTemplateController::class, 'archive'])->middleware('permission:plantillas_auditoria.editar');
        Route::post('audit-templates/{id}/restore', [FieldAuditTemplateController::class, 'restore'])->middleware('permission:plantillas_auditoria.editar');
        Route::post('audit-templates/{id}/duplicate', [FieldAuditTemplateController::class, 'duplicate'])->middleware('permission:plantillas_auditoria.crear');

        Route::get('audits/auditors', [FieldAuditController::class, 'auditors'])->middleware('permission:auditorias.crear');
        Route::get('audits', [FieldAuditController::class, 'index'])->middleware('permission:auditorias.ver');
        Route::post('audits', [FieldAuditController::class, 'store'])->middleware('permission:auditorias.crear');
        Route::get('audits/{id}', [FieldAuditController::class, 'show'])->middleware('permission:auditorias.ver');
        Route::put('audits/{id}', [FieldAuditController::class, 'update'])->middleware('permission:auditorias.editar');
        Route::post('audits/{id}/cancel', [FieldAuditController::class, 'cancel'])->middleware('permission:auditorias.eliminar');
        Route::post('audits/{id}/start', [FieldAuditController::class, 'start'])->middleware('permission:auditorias.ejecutar');
        Route::put('audits/{id}/answers', [FieldAuditController::class, 'saveAnswers'])->middleware('permission:auditorias.ejecutar');
        Route::post('audits/{id}/evidence', [FieldAuditController::class, 'uploadEvidence'])->middleware('permission:auditorias.ejecutar');
        Route::delete('audits/{id}/evidence/{evidenceId}', [FieldAuditController::class, 'deleteEvidence'])->middleware('permission:auditorias.ejecutar');
        Route::post('audits/{id}/submit', [FieldAuditController::class, 'submit'])->middleware('permission:auditorias.ejecutar');
        Route::post('audits/{id}/approve', [FieldAuditController::class, 'approve'])->middleware('permission:auditorias.aprobar');
        Route::post('audits/{id}/return', [FieldAuditController::class, 'sendBack'])->middleware('permission:auditorias.aprobar');

        Route::get('findings', [FieldAuditFindingController::class, 'index'])->middleware('permission:hallazgos.ver');
        Route::post('findings', [FieldAuditFindingController::class, 'store'])->middleware('permission:hallazgos.crear');
        Route::put('findings/{id}', [FieldAuditFindingController::class, 'update'])->middleware('permission:hallazgos.editar');
        Route::delete('findings/{id}', [FieldAuditFindingController::class, 'destroy'])->middleware('permission:hallazgos.eliminar');

        // Holdings
        Route::get('holdings', [HoldingController::class, 'index'])->middleware('permission:holdings.ver');
        Route::get('holdings/{id}', [HoldingController::class, 'show'])->middleware('permission:holdings.ver');
        Route::post('holdings', [HoldingController::class, 'store'])->middleware('permission:holdings.crear');
        Route::put('holdings/{id}', [HoldingController::class, 'update'])->middleware('permission:holdings.editar');
        Route::delete('holdings/{id}', [HoldingController::class, 'destroy'])->middleware('permission:holdings.eliminar');

        // Bitácora de auditoría
        Route::get('audit-events/meta', [AuditController::class, 'meta'])->middleware('permission:auditoria.ver');
        Route::get('audit-events/export', [AuditController::class, 'export'])->middleware('permission:auditoria.ver');
        Route::get('audit-events', [AuditController::class, 'index'])->middleware('permission:auditoria.ver');
        Route::get('audit-events/{id}', [AuditController::class, 'show'])->middleware('permission:auditoria.ver');

        // Catálogos maestros y constructor de catálogos
        Route::get('catalogs/{key}/options', [CatalogController::class, 'options']);
        Route::get('catalogs', [CatalogController::class, 'index'])->middleware('permission:catalogos.ver');
        Route::get('catalogs/{key}/items', [CatalogController::class, 'items'])->middleware('permission:catalogos.ver');
        Route::post('catalogs', [CatalogController::class, 'storeType'])->middleware('permission:catalogos.crear');
        Route::put('catalogs/{key}', [CatalogController::class, 'updateType'])->middleware('permission:catalogos.editar');
        Route::delete('catalogs/{key}', [CatalogController::class, 'destroyType'])->middleware('permission:catalogos.eliminar');
        Route::post('catalogs/{key}/items', [CatalogController::class, 'storeItem'])->middleware('permission:catalogos.crear');
        Route::put('catalogs/{key}/items/{id}', [CatalogController::class, 'updateItem'])->middleware('permission:catalogos.editar');
        Route::delete('catalogs/{key}/items/{id}', [CatalogController::class, 'destroyItem'])->middleware('permission:catalogos.eliminar');

        // Galerías de avatares y su asignación a empresas
        Route::get('avatar-collections', [AvatarAdminController::class, 'index'])->middleware('permission:avatares.ver');
        Route::post('avatar-collections', [AvatarAdminController::class, 'store'])->middleware('permission:avatares.crear');
        Route::put('avatar-collections/{id}', [AvatarAdminController::class, 'update'])->middleware('permission:avatares.editar');
        Route::delete('avatar-collections/{id}', [AvatarAdminController::class, 'destroy'])->middleware('permission:avatares.eliminar');
        Route::post('avatar-collections/{id}/avatars', [AvatarAdminController::class, 'addAvatars'])->middleware('permission:avatares.crear');
        Route::put('avatars/{id}', [AvatarAdminController::class, 'updateAvatar'])->middleware('permission:avatares.editar');
        Route::delete('avatars/{id}', [AvatarAdminController::class, 'destroyAvatar'])->middleware('permission:avatares.eliminar');
        Route::delete('users/{userId}/mfa', [UserProfileController::class, 'resetMfa'])->middleware('permission:usuarios.editar');
        Route::get('users/{userId}/profiles', [UserProfileController::class, 'index'])->middleware('permission:usuarios.ver');
        Route::post('users/{userId}/profiles', [UserProfileController::class, 'assign'])->middleware('permission:usuarios.editar');
        Route::delete('users/{userId}/profiles/{assignmentId}', [UserProfileController::class, 'revoke'])->middleware('permission:usuarios.editar');

        Route::get('organizations', [OrganizationController::class, 'index'])->middleware('permission:organizaciones.ver');
        Route::get('organizations/{id}', [OrganizationController::class, 'show'])->middleware('permission:organizaciones.ver');
        Route::post('organizations', [OrganizationController::class, 'store'])->middleware('permission:organizaciones.crear');
        Route::put('organizations/{id}', [OrganizationController::class, 'update'])->middleware('permission:organizaciones.editar');
        Route::delete('organizations/{id}', [OrganizationController::class, 'destroy'])->middleware('permission:organizaciones.eliminar');

        // Importador universal (Excel/CSV): subir -> mapear -> simular -> cargar
        Route::get('mail/status', [\App\Http\Controllers\Api\V1\MailController::class, 'status'])->middleware('permission:configuracion.ver');
        Route::post('mail/test', [\App\Http\Controllers\Api\V1\MailController::class, 'test'])->middleware('permission:configuracion.editar');
        Route::post('users/{id}/send-access', [\App\Http\Controllers\Api\V1\MailController::class, 'sendAccess'])->middleware('permission:usuarios.editar');
        $so = \App\Http\Controllers\Api\V1\SelloutController::class;
        $sp = \App\Http\Controllers\Api\V1\SelloutProductController::class;
        $sr = \App\Http\Controllers\Api\V1\SelloutRequestController::class;
        Route::get('sellout/clients', [$so, 'clients'])->middleware('permission:sellout.ver');
        Route::get('sellout/dataset', [$so, 'dataset'])->middleware('permission:sellout.ver');
        Route::get('sellout/chains', [$so, 'chains'])->middleware('permission:sellout.ver');
        Route::post('sellout/chains', [$so, 'storeChain'])->middleware('permission:sellout.editar');
        Route::put('sellout/chains/{id}', [$so, 'updateChain'])->middleware('permission:sellout.editar');
        Route::get('sellout/points', [$so, 'points'])->middleware('permission:sellout.ver');
        Route::put('sellout/points/{id}', [$so, 'updatePoint'])->middleware('permission:sellout.editar');
        Route::get('sellout/points/{id}/detail', [$so, 'pointDetail'])->middleware('permission:sellout.ver');
        Route::post('sellout/points/{id}/notes', [$so, 'addNote'])->middleware('permission:sellout.campo');
        Route::post('sellout/points/{id}/photos', [$so, 'addPhoto'])->middleware('permission:sellout.campo');
        Route::delete('sellout/photos/{id}', [$so, 'deletePhoto'])->middleware('permission:sellout.campo');
        Route::get('sellout/products', [$sp, 'index'])->middleware('permission:sellout.ver');
        Route::post('sellout/products', [$sp, 'store'])->middleware('permission:sellout.editar');
        Route::put('sellout/products/{id}', [$sp, 'update'])->middleware('permission:sellout.editar');
        Route::delete('sellout/products/{id}', [$sp, 'destroy'])->middleware('permission:sellout.editar');
        Route::post('sellout/products/{id}/image', [$sp, 'image'])->middleware('permission:sellout.editar');
        Route::delete('sellout/products/{id}/image', [$sp, 'removeImage'])->middleware('permission:sellout.editar');
        Route::get('sellout/requests', [$sr, 'index'])->middleware('permission:sellout_solicitudes.ver');
        Route::post('sellout/requests', [$sr, 'store'])->middleware('permission:sellout_solicitudes.crear');
        Route::post('sellout/requests/{id}/respond', [$sr, 'respond'])->middleware('permission:sellout_solicitudes.atender');
        Route::post('sellout/requests/{id}/close', [$sr, 'close'])->middleware('permission:sellout_solicitudes.crear');
        Route::post('sellout/requests/{id}/reopen', [$sr, 'reopen'])->middleware('permission:sellout_solicitudes.crear');
        Route::post('imports/{id}/sheet', [\App\Http\Controllers\Api\V1\ImportController::class, 'sheet'])->middleware('permission:importaciones.crear');
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
