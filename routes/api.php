<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Api\V1\ActivacionDistribuidoraController;
use App\Http\Controllers\Api\V1\ArchivoPrivadoController;
use App\Http\Controllers\Api\V1\AsignacionCategoriaDistribuidoraController;
use App\Http\Controllers\Api\V1\Auth\AuthConfigurationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\LocalAccountSwitchController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\CajaValeController;
use App\Http\Controllers\Api\V1\CarteraInformativaClienteController;
use App\Http\Controllers\Api\V1\CatalogoVehiculosSolicitudController;
use App\Http\Controllers\Api\V1\CategoriaController;
use App\Http\Controllers\Api\V1\CentroOperacionController;
use App\Http\Controllers\Api\V1\ClienteController;
use App\Http\Controllers\Api\V1\ConciliacionBancariaController;
use App\Http\Controllers\Api\V1\ConfiguracionController;
use App\Http\Controllers\Api\V1\CoordinatorAssignmentController;
use App\Http\Controllers\Api\V1\Credito\CrearSolicitudIncrementoController;
use App\Http\Controllers\Api\V1\Credito\LineaCreditoConsultaController;
use App\Http\Controllers\Api\V1\Credito\MovimientoLineaCreditoConsultaController;
use App\Http\Controllers\Api\V1\Credito\SolicitudIncrementoLineaConsultaController;
use App\Http\Controllers\Api\V1\CuentaBancariaClienteController;
use App\Http\Controllers\Api\V1\DistribuidoraController;
use App\Http\Controllers\Api\V1\ErrorCatalogController;
use App\Http\Controllers\Api\V1\EstadoOperativoController;
use App\Http\Controllers\Api\V1\ExcedenteController;
use App\Http\Controllers\Api\V1\InvitationListController;
use App\Http\Controllers\Api\V1\LineaCreditoController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PreparacionActivacionDistribuidoraController;
use App\Http\Controllers\Api\V1\ProductoController;
use App\Http\Controllers\Api\V1\PuntosController;
use App\Http\Controllers\Api\V1\ReenvioInvitacionDistribuidoraController;
use App\Http\Controllers\Api\V1\RelacionDistribuidoraController;
use App\Http\Controllers\Api\V1\ReportesExportController;
use App\Http\Controllers\Api\V1\ResumenInicioDistribuidoraController;
use App\Http\Controllers\Api\V1\ResumenOperacionController;
use App\Http\Controllers\Api\V1\RiesgoDistribuidoraController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\SecurityEventController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SolicitudDistribuidoraController;
use App\Http\Controllers\Api\V1\SolicitudIncrementoLineaController;
use App\Http\Controllers\Api\V1\TransferenciaOrganizacionalController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ValeController;
use App\Http\Controllers\VerificacionDistribuidora\AutorizacionSolicitudController;
use App\Http\Controllers\VerificacionDistribuidora\CorreccionSolicitudController;
use App\Http\Controllers\VerificacionDistribuidora\EvaluacionSolicitudController;
use App\Http\Controllers\VerificacionDistribuidora\EvidenciaVerificacionController;
use App\Http\Controllers\VerificacionDistribuidora\VerificacionDistribuidoraController;
use App\Http\Middleware\RejectBranchManagerAdministration;
use App\Modules\Organization\Presentation\Http\Controllers\BranchAssignmentController;
use App\Modules\Organization\Presentation\Http\Controllers\BranchController;
use App\Modules\Organization\Presentation\Http\Controllers\BranchPersonnelController;
use App\Modules\Organization\Presentation\Http\Controllers\PersonnelController;
use App\Modules\Organization\Presentation\Http\Controllers\UserAssignmentCommandController;
use App\Modules\Organization\Presentation\Http\Controllers\UserAssignmentQueryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health/readiness', [EstadoOperativoController::class, 'readiness'])->middleware('throttle:60,1');
    Route::get('metrics', [EstadoOperativoController::class, 'metrics'])->middleware([
        'auth:sanctum', 'active.user', 'mfa.completed', 'permission:audit.view_global', 'require.vpn:always', 'throttle:60,1',
    ]);
    Route::prefix('auth')->group(function () {
        Route::get('configuration', AuthConfigurationController::class)->middleware('throttle:60,1');
        Route::post('invitations/inspect', [InvitationController::class, 'inspect'])->middleware('throttle:inspect_invitation');
        Route::post('invitations/resend', [InvitationController::class, 'resend'])->middleware('throttle:resend_invitation');
        Route::post('invitations/setup', [InvitationController::class, 'setup']);
        Route::post('invitations/passkey/setup', [InvitationController::class, 'passkeySetup']);
        Route::post('invitations/passkey/register', [InvitationController::class, 'passkeyRegister']);
        Route::post('invitations/complete', [InvitationController::class, 'complete']);

        Route::post('login', [AuthController::class, 'login']); // Protegido manual por el controlador y el servicio ciego
        Route::post('mfa/totp/verify', [AuthController::class, 'verifyTotp'])->middleware('throttle:totp');
        Route::post('mfa/development/skip', [AuthController::class, 'skipDevelopmentMfa'])->middleware('throttle:totp');
        Route::post('mfa/passkey/options', [AuthController::class, 'passkeyOptions'])->middleware('throttle:totp');
        Route::post('mfa/passkey/verify', [AuthController::class, 'passkeyVerify'])->middleware('throttle:totp');
        Route::post('mfa/recovery-code/verify', [AuthController::class, 'verifyRecoveryCode'])->middleware('throttle:recovery_code');
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('password/forgot', [ForgotPasswordController::class, 'forgotPassword'])->middleware('throttle:forgot_password');
        Route::post('password/reset', [ResetPasswordController::class, 'resetPassword'])->middleware('throttle:reset_password');
        // Selector exclusivo de desarrollo local: permite entrar a una cuenta demo sin credenciales.
        Route::get('local/accounts', [LocalAccountSwitchController::class, 'index']);
        Route::post('local/switch-account', [LocalAccountSwitchController::class, 'store']);

        // Rutas protegidas de la API (Zero Trust Layer)
        Route::middleware(['auth:sanctum', 'track.activity', 'active.user', 'mfa.completed'])->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    // Rutas públicas o protegidas para el catálogo de direcciones
    Route::prefix('address')->group(function () {
        Route::get('states', [AddressController::class, 'getStates']);
        Route::get('states/{estado}/municipalities', [AddressController::class, 'getMunicipalities']);
        Route::get('zip-codes/{code}', [AddressController::class, 'getInfoByZipCode']);
        Route::post('autocomplete', [AddressController::class, 'autocomplete']);
        Route::post('geocode', [AddressController::class, 'geocode']);
    });

    // Perfil y Permisos
    Route::middleware(['auth:sanctum', 'track.activity', 'active.user', 'mfa.completed', 'require.vpn'])->group(function () {

        // Módulo 4 - Solicitud Distribuidora
        Route::get('distributor-applications', [SolicitudDistribuidoraController::class, 'index']);
        Route::post('distributor-applications', [SolicitudDistribuidoraController::class, 'store']);
        Route::get('distributor-applications/vehicle-catalog', CatalogoVehiculosSolicitudController::class);
        Route::get('distributor-applications/{application}', [SolicitudDistribuidoraController::class, 'show']);
        Route::patch('distributor-applications/{application}', [SolicitudDistribuidoraController::class, 'update']);
        Route::post('distributor-applications/{application}/submit', [SolicitudDistribuidoraController::class, 'enviarARevision']);
        Route::put('distributor-applications/{application}/personal-data', [SolicitudDistribuidoraController::class, 'guardarDatosPersonales']);
        Route::get('distributor-applications/{application}/family-members', [SolicitudDistribuidoraController::class, 'listarFamiliares']);
        Route::post('distributor-applications/{application}/family-members', [SolicitudDistribuidoraController::class, 'crearFamiliar']);
        Route::patch('distributor-applications/{application}/family-members/{member}', [SolicitudDistribuidoraController::class, 'actualizarFamiliar']);
        Route::delete('distributor-applications/{application}/family-members/{member}', [SolicitudDistribuidoraController::class, 'eliminarFamiliar']);
        Route::get('distributor-applications/{application}/residences', [SolicitudDistribuidoraController::class, 'listarDomicilios']);
        Route::post('distributor-applications/{application}/residences', [SolicitudDistribuidoraController::class, 'crearDomicilio']);
        Route::patch('distributor-applications/{application}/residences/{residence}', [SolicitudDistribuidoraController::class, 'actualizarDomicilio']);
        Route::delete('distributor-applications/{application}/residences/{residence}', [SolicitudDistribuidoraController::class, 'eliminarDomicilio']);
        Route::get('distributor-applications/{application}/vehicles', [SolicitudDistribuidoraController::class, 'listarVehiculos']);
        Route::post('distributor-applications/{application}/vehicles', [SolicitudDistribuidoraController::class, 'crearVehiculo']);
        Route::patch('distributor-applications/{application}/vehicles/{vehicle}', [SolicitudDistribuidoraController::class, 'actualizarVehiculo']);
        Route::delete('distributor-applications/{application}/vehicles/{vehicle}', [SolicitudDistribuidoraController::class, 'eliminarVehiculo']);
        Route::get('distributor-applications/{application}/assets-liabilities', [SolicitudDistribuidoraController::class, 'listarPatrimonio']);
        Route::post('distributor-applications/{application}/assets-liabilities', [SolicitudDistribuidoraController::class, 'crearPatrimonio']);
        Route::patch('distributor-applications/{application}/assets-liabilities/{entry}', [SolicitudDistribuidoraController::class, 'actualizarPatrimonio']);
        Route::delete('distributor-applications/{application}/assets-liabilities/{entry}', [SolicitudDistribuidoraController::class, 'eliminarPatrimonio']);
        Route::get('distributor-applications/{application}/employments', [SolicitudDistribuidoraController::class, 'listarEmpleos']);
        Route::post('distributor-applications/{application}/employments', [SolicitudDistribuidoraController::class, 'crearEmpleo']);
        Route::patch('distributor-applications/{application}/employments/{employment}', [SolicitudDistribuidoraController::class, 'actualizarEmpleo']);
        Route::delete('distributor-applications/{application}/employments/{employment}', [SolicitudDistribuidoraController::class, 'eliminarEmpleo']);
        Route::get('distributor-applications/{application}/commercial-credits', [SolicitudDistribuidoraController::class, 'listarCreditosComerciales']);
        Route::post('distributor-applications/{application}/commercial-credits', [SolicitudDistribuidoraController::class, 'crearCreditoComercial']);
        Route::patch('distributor-applications/{application}/commercial-credits/{credit}', [SolicitudDistribuidoraController::class, 'actualizarCreditoComercial']);
        Route::delete('distributor-applications/{application}/commercial-credits/{credit}', [SolicitudDistribuidoraController::class, 'eliminarCreditoComercial']);
        Route::get('me', [MeController::class, 'show']);

        // Gestión de Sesiones (Puntos 23, 24, 25)
        Route::get('me/sessions', [SessionController::class, 'index']);
        Route::delete('me/sessions', [SessionController::class, 'destroyOther']);
        Route::delete('me/sessions/{id}', [SessionController::class, 'destroy']);

        // Seguridad y Configuraciones de Cuenta (Puntos 30, 31, 32)
        Route::post('me/security/password', [SecurityController::class, 'changePassword']);
        Route::post('me/security/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes']);
        Route::get('me/security/totp/setup', [SecurityController::class, 'totpSetup']);
        Route::post('me/security/totp/validate-current', [SecurityController::class, 'validateCurrentTotp']);
        Route::post('me/security/totp/confirm', [SecurityController::class, 'totpConfirm']);
        Route::get('me/security/passkeys', [SecurityController::class, 'passkeys']);
        Route::post('me/security/passkeys/options', [SecurityController::class, 'passkeyOptions']);
        Route::post('me/security/passkeys/register', [SecurityController::class, 'passkeyRegister']);
        Route::delete('me/security/passkeys/{id}', [SecurityController::class, 'deletePasskey']);

        // Gestión de Usuarios (Punto 33)
        Route::apiResource('users', UserController::class)->except(['destroy']);
        Route::post('users/{id}/invite', [UserController::class, 'invite'])->middleware('throttle:resend_invitation');
        Route::post('users/{id}/block', [UserController::class, 'block']);
        Route::post('users/{id}/unblock', [UserController::class, 'unblock']);
        Route::post('users/{id}/disable', [UserController::class, 'disable']);
        Route::post('users/{id}/enable', [UserController::class, 'enable']);
        Route::post('users/{id}/require-password-change', [UserController::class, 'requirePasswordChange']);

        // Roles y Permisos (Punto 34)
        Route::get('permissions', [PermissionController::class, 'index']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/assignable', [RoleController::class, 'assignable']);
        Route::get('roles/{id}', [RoleController::class, 'show']);
        Route::put('roles/{id}/permissions', [RoleController::class, 'syncPermissions']);

        // Auditoría
        Route::get('security-events', [SecurityEventController::class, 'index']);
        Route::get('error-catalog', ErrorCatalogController::class);

        // Invitaciones
        Route::get('invitations', [InvitationListController::class, 'index']);
        Route::post('invitations/{invitation}/revoke', [InvitationListController::class, 'revoke']);

        // Asignaciones Jerárquicas (Punto 35)
        Route::get('users/{id}/assignments', [UserAssignmentQueryController::class, 'index']);
        Route::post('users/{id}/assignments', [UserAssignmentCommandController::class, 'store']);
        Route::patch('users/{id}/assignments/{assignmentId}', [UserAssignmentCommandController::class, 'update']);
        Route::delete('users/{id}/assignments/{assignmentId}', [UserAssignmentCommandController::class, 'destroy']);

        // Módulo 2 - Sucursales y Personal
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('branches/{id}', [BranchController::class, 'show'])->name('branches.show');
        Route::match(['put', 'patch'], 'branches/{id}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('branches/{id}/activate', [BranchController::class, 'activate']);
        Route::post('branches/{id}/deactivate', [BranchController::class, 'deactivate']);
        Route::patch('branches/{id}/status', [BranchController::class, 'changeStatus']);

        Route::get('branches/{id}/personnel', [BranchPersonnelController::class, 'index']);
        Route::get('branches/{id}/assignments', [BranchAssignmentController::class, 'index']);
        Route::get('personnel', [PersonnelController::class, 'index']);

        // Módulo 2 - Asignación Coordinador - Distribuidora
        Route::get('assignments/distributors', [CoordinatorAssignmentController::class, 'distributors']);
        Route::get('assignments/coordinator-distributor', [CoordinatorAssignmentController::class, 'index']);
        Route::post('assignments/coordinator-distributor', [CoordinatorAssignmentController::class, 'store']);
        Route::delete('assignments/coordinator-distributor/{assignment}', [CoordinatorAssignmentController::class, 'destroy']);

        // Módulo 03 - Configuraciones y Catálogos
        Route::middleware(RejectBranchManagerAdministration::class)->group(function (): void {
            // Configuraciones
            Route::get('configurations', [ConfiguracionController::class, 'index']);
            Route::post('configurations', [ConfiguracionController::class, 'store']);
            Route::get('configurations/{key}', [ConfiguracionController::class, 'show']);
            Route::get('configurations/{key}/versions', [ConfiguracionController::class, 'getVersionsByKey']);
            Route::post('configurations/{key}/versions', [ConfiguracionController::class, 'storeVersionByKey']);
            Route::put('configurations/{key}/current', [ConfiguracionController::class, 'updateCurrent']);
            Route::get('configuration-versions/{id}', [ConfiguracionController::class, 'showVersion']);
            Route::patch('configuration-versions/{id}', [ConfiguracionController::class, 'updateVersion']);
            Route::post('configuration-versions/{id}/publish', [ConfiguracionController::class, 'publishVersion']);
            Route::post('configuration-versions/{id}/deactivate', [ConfiguracionController::class, 'deactivateVersion']);

            // Categorías
            Route::get('categories', [CategoriaController::class, 'index']);
            Route::post('categories', [CategoriaController::class, 'store']);
            Route::get('categories/{id}', [CategoriaController::class, 'show']);
            Route::get('categories/{id}/versions', [CategoriaController::class, 'getVersions']);
            Route::post('categories/{id}/versions', [CategoriaController::class, 'storeVersion']);
            Route::get('category-versions/{id}', [CategoriaController::class, 'showVersion']);
            Route::patch('category-versions/{id}', [CategoriaController::class, 'updateVersion']);
            Route::post('category-versions/{id}/publish', [CategoriaController::class, 'publishVersion']);
            Route::post('categories/{id}/deactivate', [CategoriaController::class, 'deactivateCategory']);

            // Productos
            Route::get('products', [ProductoController::class, 'index']);
            Route::post('products', [ProductoController::class, 'store']);
            Route::get('products/{id}', [ProductoController::class, 'show']);
            Route::get('products/{id}/versions', [ProductoController::class, 'getVersions']);
            Route::post('products/{id}/versions', [ProductoController::class, 'storeVersion']);
            Route::get('product-versions/{id}', [ProductoController::class, 'showVersion']);
            Route::patch('product-versions/{id}', [ProductoController::class, 'updateVersion']);
            Route::post('product-versions/{id}/publish', [ProductoController::class, 'publishVersion']);
            Route::post('products/{id}/deactivate', [ProductoController::class, 'deactivateProduct']);
        });

        // Módulo 5 - verificación, corrección, evaluación y dictamen
        Route::post('distributor-applications/{application}/return-to-draft', [VerificacionDistribuidoraController::class, 'devolverACaptura']);
        Route::post('distributor-applications/{application}/assign-verifier', [VerificacionDistribuidoraController::class, 'asignarVerificador']);
        Route::get('distributor-applications/{application}/available-verifiers', [VerificacionDistribuidoraController::class, 'listarVerificadoresDisponibles']);
        Route::get('distributor-applications/{application}/verifiers/{verifier}/schedule', [VerificacionDistribuidoraController::class, 'consultarAgendaVerificador']);
        Route::get('verification-schedule-policy', [VerificacionDistribuidoraController::class, 'politicaHorario']);
        Route::get('verification-visits/assigned', [VerificacionDistribuidoraController::class, 'consultarAsignadas']);
        Route::get('verification-visits/{visit}', [VerificacionDistribuidoraController::class, 'consultarVisita']);
        Route::post('verification-visits/{visit}/start', [VerificacionDistribuidoraController::class, 'iniciarVisita']);
        Route::put('verification-visits/{visit}', [VerificacionDistribuidoraController::class, 'actualizarVisita']);
        Route::put('verification-visits/{visit}/differences', [VerificacionDistribuidoraController::class, 'registrarDiferencias']);
        Route::post('verification-visits/{visit}/finish', [VerificacionDistribuidoraController::class, 'finalizarVisita']);
        Route::get('verification-visits/{visit}/evidences', [EvidenciaVerificacionController::class, 'consultarEvidencia']);
        Route::post('verification-visits/{visit}/evidences', [EvidenciaVerificacionController::class, 'adjuntarEvidencia']);
        Route::get('verification-evidences/{media}/download', [EvidenciaVerificacionController::class, 'descargarEvidencia']);
        Route::delete('verification-evidences/{media}', [EvidenciaVerificacionController::class, 'eliminarEvidenciaAbierta']);
        Route::get('distributor-applications/{application}/corrections', [CorreccionSolicitudController::class, 'listarDiferencias']);
        Route::post('distributor-applications/{application}/corrections', [CorreccionSolicitudController::class, 'aplicarCorreccion']);
        Route::post('distributor-applications/{application}/corrections/finish', [CorreccionSolicitudController::class, 'finalizarCorrecciones']);
        Route::get('distributor-applications/{application}/evaluations', [EvaluacionSolicitudController::class, 'consultarEvaluacion']);
        Route::post('distributor-applications/{application}/evaluate', [EvaluacionSolicitudController::class, 'evaluar']);
        Route::get('distributor-applications/{application}/authorization', [AutorizacionSolicitudController::class, 'consultarAutorizacion']);
        Route::post('distributor-applications/{application}/authorize', [AutorizacionSolicitudController::class, 'autorizar']);

        // Módulo 6 - Activación y administración de distribuidoras
        Route::get('distributor-activation/authorized-applications', [PreparacionActivacionDistribuidoraController::class, 'solicitudes'])
            ->middleware('permission:distributors.activate');
        Route::get('distributor-activation/categories', [PreparacionActivacionDistribuidoraController::class, 'categorias'])
            ->middleware('permission:distributors.activate');
        Route::post('distributor-applications/{application}/activation', [ActivacionDistribuidoraController::class, 'store'])
            ->middleware(['permission:distributors.activate', 'idempotency']);
        Route::get('distributors', [DistribuidoraController::class, 'index'])
            ->middleware('permission:distributors.view_any');
        Route::get('distributors/{distributor}', [DistribuidoraController::class, 'show'])
            ->middleware('permission:distributors.view');
        Route::get('distributors/{distributor}/category-assignments', [AsignacionCategoriaDistribuidoraController::class, 'index'])
            ->middleware('permission:distributors.view_category_history');
        Route::post('distributors/{distributor}/category-assignments', [AsignacionCategoriaDistribuidoraController::class, 'store'])
            ->middleware('permission:distributors.assign_category');
        Route::post('distributors/{distributor}/activation-invitations/resend', [ReenvioInvitacionDistribuidoraController::class, 'store'])
            ->middleware(['permission:distributors.resend_activation', 'throttle:resend_invitation']);

        // Módulo 7 - Clientes finales y cartera informativa
        Route::get('clients', [ClienteController::class, 'index'])->middleware('permission:clients.view');
        Route::post('clients', [ClienteController::class, 'store'])->middleware(['permission:clients.create', 'idempotency']);
        Route::post('client-registration-drafts', [ClienteController::class, 'createRegistrationDraft'])->middleware(['permission:clients.create', 'idempotency']);
        Route::post('client-registration-drafts/{draft}/complete', [ClienteController::class, 'completeRegistrationDraft'])->middleware(['permission:clients.create', 'idempotency']);
        Route::post('voucher-clients', [ClienteController::class, 'storeForVoucher'])->middleware(['permission:clients.create', 'idempotency']);
        Route::get('clients/{client}', [ClienteController::class, 'show'])->middleware('permission:clients.view');
        Route::get('clients/{client}/bank-accounts', [CuentaBancariaClienteController::class, 'index'])
            ->middleware('permission:clients.view_bank_accounts');
        Route::post('clients/{client}/bank-accounts', [CuentaBancariaClienteController::class, 'store'])
            ->middleware('permission:clients.manage_bank_accounts');
        Route::get('clients/{client}/portfolio-entries', [CarteraInformativaClienteController::class, 'index'])
            ->middleware('permission:clients.view_portfolio');
        Route::post('clients/{client}/portfolio-entries', [CarteraInformativaClienteController::class, 'store'])
            ->middleware(['permission:clients.manage_portfolio', 'idempotency']);
        Route::patch('clients/{client}/portfolio-entries/{entry}', [CarteraInformativaClienteController::class, 'update'])
            ->middleware('permission:clients.manage_portfolio');

        // Módulo 08 - Líneas de Crédito e Incrementos
        Route::get('credit-lines', [LineaCreditoConsultaController::class, 'index']);
        Route::get('distributors/{distributor}/credit-line', [LineaCreditoConsultaController::class, 'show']);
        Route::get('distributors/{distributor}/credit-line/movements', [MovimientoLineaCreditoConsultaController::class, 'index']);
        Route::post('distributors/{distributor}/credit-increase-requests', [CrearSolicitudIncrementoController::class, 'store'])->middleware('idempotency');
        Route::get('me/credit-line', [LineaCreditoController::class, 'me']);
        Route::get('credit-increase-requests', [SolicitudIncrementoLineaConsultaController::class, 'index']);
        Route::get('credit-increase-requests/{solicitud}', [SolicitudIncrementoLineaConsultaController::class, 'show']);
        Route::post('credit-increase-requests/{solicitud}/preauthorize', [SolicitudIncrementoLineaController::class, 'preauthorize']);
        Route::post('credit-increase-requests/{solicitud}/reject-by-coordinator', [SolicitudIncrementoLineaController::class, 'rejectByCoordinator']);
        Route::post('credit-increase-requests/{solicitud}/manager-decision', [SolicitudIncrementoLineaController::class, 'decide'])
            ->middleware('idempotency');

        // Módulo 09 - Prevales, vales digitales y motor financiero
        Route::get('voucher-products', [ValeController::class, 'products']);
        Route::get('vouchers/eligible-clients', [ValeController::class, 'eligibleClients']);
        Route::get('vouchers/financial-context', [ValeController::class, 'financialContext']);
        Route::post('vouchers/preview', [ValeController::class, 'preview']);
        Route::post('vouchers', [ValeController::class, 'store'])->middleware('idempotency');
        Route::get('vouchers', [ValeController::class, 'index']);
        Route::get('vouchers/{vale}', [ValeController::class, 'show']);
        Route::post('vouchers/{vale}/cancel', [ValeController::class, 'cancel'])->middleware('idempotency');

        // Módulo 10 - Caja, modificaciones autorizadas y feriado
        Route::get('dashboard/operations', ResumenOperacionController::class);
        Route::get('dashboard/distributor-summary', ResumenInicioDistribuidoraController::class);
        Route::get('cashier/vouchers', [CajaValeController::class, 'index']);
        Route::get('cashier/vouchers/search', [CajaValeController::class, 'search']);
        Route::get('cashier/vouchers/{vale}', [CajaValeController::class, 'show']);
        Route::post('cashier/vouchers/{vale}/release', [CajaValeController::class, 'release'])->middleware('idempotency');
        Route::post('cashier/vouchers/{vale}/cash', [CajaValeController::class, 'cash'])->middleware('idempotency');
        Route::post('cashier/vouchers/{vale}/modification-requests', [CajaValeController::class, 'requestModification'])->middleware('idempotency');
        Route::get('voucher-modification-requests', [CajaValeController::class, 'listModifications']);
        Route::post('voucher-modification-requests/{solicitud}/decision', [CajaValeController::class, 'decideModification'])->middleware('idempotency');
        Route::post('voucher-modification-requests/{solicitud}/apply', [CajaValeController::class, 'applyModification'])->middleware('idempotency');

        // Módulo 11 - Cortes, parcialidades y relaciones
        Route::get('relations', [RelacionDistribuidoraController::class, 'index']);
        Route::get('relations/{relacion}', [RelacionDistribuidoraController::class, 'show']);
        Route::get('relations/{relacion}/download', [RelacionDistribuidoraController::class, 'download']);
        Route::get('distributors/{distribuidora}/account-statement', [RelacionDistribuidoraController::class, 'accountStatement']);

        // Módulo 12 - Archivo bancario y conciliación automática
        Route::get('bank-reconciliation-periods', [ConciliacionBancariaController::class, 'pendingPeriods']);
        Route::post('bank-imports', [ConciliacionBancariaController::class, 'import']);
        Route::get('bank-imports', [ConciliacionBancariaController::class, 'imports']);
        Route::post('bank-simulations', [ConciliacionBancariaController::class, 'simulate']);
        Route::get('bank-simulations', [ConciliacionBancariaController::class, 'simulations']);
        Route::get('bank-simulations/export', [ConciliacionBancariaController::class, 'exportSimulations']);
        Route::get('bank-simulations/{transferencia}/ticket', [ConciliacionBancariaController::class, 'downloadSimulationTicket']);
        Route::get('bank-movements', [ConciliacionBancariaController::class, 'movements']);
        Route::get('payment-clarifications', [ConciliacionBancariaController::class, 'clarifications']);
        Route::get('manual-reconciliation-requests', [ConciliacionBancariaController::class, 'manualRequests']);
        Route::post('relations/{relacion}/clarifications', [ConciliacionBancariaController::class, 'clarify']);
        Route::post('bank-movements/{movimiento}/manual-reconciliation-requests', [ConciliacionBancariaController::class, 'requestManual']);
        Route::post('manual-reconciliation-requests/{solicitud}/decision', [ConciliacionBancariaController::class, 'decideManual']);
        Route::post('manual-reconciliation-requests/{solicitud}/execute', [ConciliacionBancariaController::class, 'executeManual']);

        // Módulo 14 - Recargos, excedentes y devoluciones
        Route::get('surpluses', [ExcedenteController::class, 'index']);
        Route::get('surpluses/{excedente}', [ExcedenteController::class, 'show']);
        Route::post('surpluses/{excedente}/credit-balance', [ExcedenteController::class, 'credit'])->middleware('idempotency');
        Route::post('surpluses/{excedente}/refund-requests', [ExcedenteController::class, 'refund'])->middleware('idempotency');
        Route::get('refund-requests', [ExcedenteController::class, 'refunds']);
        Route::post('refund-requests/{solicitud}/decision', [ExcedenteController::class, 'decide'])->middleware('idempotency');
        Route::post('refund-requests/{solicitud}/cancel', [ExcedenteController::class, 'cancel'])->middleware('idempotency');
        Route::post('refund-requests/{solicitud}/execute', [ExcedenteController::class, 'execute'])->middleware('idempotency');

        // Módulo 16 - Riesgo y morosidad exclusiva de distribuidora
        Route::get('risk-alerts', [RiesgoDistribuidoraController::class, 'alerts']);
        Route::get('delinquency-blocks', [RiesgoDistribuidoraController::class, 'delinquencyBlocks']);
        Route::get('me/delinquency-status', [RiesgoDistribuidoraController::class, 'me']);
        Route::post('risk-alerts/{alerta}/decision', [RiesgoDistribuidoraController::class, 'decide'])->middleware('idempotency');
        Route::post('distributors/{distribuidora}/delinquency-removal-requests', [RiesgoDistribuidoraController::class, 'requestRemoval'])->middleware('idempotency');
        Route::post('distributors/{distribuidora}/delinquency-removal', [RiesgoDistribuidoraController::class, 'removeDirectly'])->middleware('idempotency');
        Route::get('delinquency-removal-requests', [RiesgoDistribuidoraController::class, 'removals']);
        Route::post('delinquency-removal-requests/{solicitud}/decision', [RiesgoDistribuidoraController::class, 'decideRemoval'])->middleware('idempotency');
        Route::post('distributors/{distributor}/coordinator-change', [TransferenciaOrganizacionalController::class, 'changeCoordinator'])->middleware('idempotency');
        Route::get('organizational-change-history', [TransferenciaOrganizacionalController::class, 'history']);
        Route::get('notifications', [CentroOperacionController::class, 'notifications'])->middleware('throttle:realtime_reads');
        Route::get('notifications/unread-count', [CentroOperacionController::class, 'unreadCount'])->middleware('throttle:realtime_reads');
        Route::patch('notifications/{notification}/read', [CentroOperacionController::class, 'markNotification']);
        Route::post('notifications/read-all', [CentroOperacionController::class, 'markAllNotifications'])->middleware('idempotency');

        Route::get('operations/current-cutoff', [CentroOperacionController::class, 'currentCutoffSummary']);
        Route::post('operations/force-cutoff', [CentroOperacionController::class, 'forceCutoff'])->middleware('idempotency');
        Route::post('operations/force-payment-deadline', [CentroOperacionController::class, 'forcePaymentDeadline'])->middleware('idempotency');

        // Módulo - Puntos y canje por dinero
        Route::get('points/balance', [PuntosController::class, 'balance']);
        Route::get('points/redemptions', [PuntosController::class, 'redemptions']);
        Route::get('points/redemptions/{redemption}', [PuntosController::class, 'show']);
        Route::post('points/redemptions', [PuntosController::class, 'store'])->middleware('idempotency');
        Route::post('points/redemptions/{redemption}/authorize', [PuntosController::class, 'authorizeRequest'])->middleware('idempotency');
        Route::post('points/redemptions/{redemption}/reject', [PuntosController::class, 'rejectRequest'])->middleware('idempotency');
        Route::post('points/redemptions/{redemption}/deliver', [PuntosController::class, 'deliverRequest'])->middleware('idempotency');

        Route::get('reports/points-balance/export', [ReportesExportController::class, 'pointsBalance']);
        Route::get('reports/pre-requests/export', [ReportesExportController::class, 'preRequests']);
        Route::get('reports', [CentroOperacionController::class, 'reports']);
        Route::get('reports/home', [CentroOperacionController::class, 'reportsHome']);
        Route::get('reports/{report}', [CentroOperacionController::class, 'report']);
        Route::get('audit-logs/options', [CentroOperacionController::class, 'auditOptions']);
        Route::get('audit-logs', [CentroOperacionController::class, 'audits']);
        Route::get('operational-logs', [CentroOperacionController::class, 'logs']);
        Route::post('media', [ArchivoPrivadoController::class, 'store'])->middleware(['idempotency', 'throttle:20,1']);
        Route::get('media/{media}/download', [ArchivoPrivadoController::class, 'download'])->middleware('throttle:60,1');
    });
});
