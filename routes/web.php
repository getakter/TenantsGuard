<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BaselineController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\ControlController;
use App\Http\Controllers\ControlPlanController;
use App\Http\Controllers\CopilotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FixController;
use App\Http\Controllers\IdentityDirectoryController;
use App\Http\Controllers\IntuneController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\MicrosoftAuthController;
use App\Http\Controllers\MicrosoftSetupController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurviewController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\SecureScoreController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantDashboardController;
use App\Http\Controllers\TenantIntuneController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserTemplateController;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::pattern('tenant', '[0-9]+');
Route::pattern('baseline', '[0-9]+');
Route::pattern('user', '[0-9]+');
Route::pattern('role', '[0-9]+');
Route::pattern('bundle', '[0-9]+');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/auth/microsoft', [MicrosoftAuthController::class, 'redirect'])->name('auth.microsoft');
});
// Not behind "guest": the popup may finish after the opener already has a session.
Route::get('/auth/microsoft/callback', [MicrosoftAuthController::class, 'callback'])->name('auth.microsoft.callback');
// Public: a customer's admin may grant consent from a link in their own browser. The state is encrypted.
Route::get('/consent/callback', [ConsentController::class, 'callback'])->middleware('throttle:20,1')->name('consent.callback');

// ---------------- MFA (second sign-in step; no full session yet) ----------------
Route::get('/mfa', [MfaController::class, 'challenge'])->name('mfa.challenge');
Route::post('/mfa', [MfaController::class, 'verify'])->middleware('throttle:mfa')->name('mfa.verify');
Route::get('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
Route::post('/mfa/setup', [MfaController::class, 'confirm'])->middleware('throttle:mfa')->name('mfa.confirm');
Route::get('/mfa/recovery-codes', [MfaController::class, 'recovery'])->name('mfa.recovery');
Route::post('/mfa/cancel', [MfaController::class, 'cancel'])->name('mfa.cancel');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', EnsureUserIsActive::class, EnsureMfaVerified::class])->group(function () {
    Route::post('/profile/mfa/recovery-codes', [MfaController::class, 'regenerate'])->middleware('throttle:mfa')->name('mfa.regenerate');
    Route::post('/profile/mfa/replace', [MfaController::class, 'replace'])->middleware('throttle:mfa')->name('mfa.replace');
    Route::post('/profile/mfa/remove', [MfaController::class, 'removeOwn'])->middleware('throttle:mfa')->name('mfa.remove');
    Route::post('/users/{user}/mfa/reset', [UserController::class, 'resetMfa'])->middleware('can:users.manage')->name('users.mfa.reset');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

    // ---------------- view ----------------
    Route::middleware('can:tenants.view')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/controls', [ControlController::class, 'index'])->name('controls.index');
        Route::get('/baselines', [BaselineController::class, 'index'])->name('baselines.index');
        Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->middleware('can:access-tenant,tenant')->name('tenants.show');
        Route::get('/tenants/{tenant}/dashboard', [TenantDashboardController::class, 'show'])->middleware('can:access-tenant,tenant')->name('tenants.dashboard');
        Route::get('/tenants/{tenant}/people/users/{person}', [TenantDashboardController::class, 'user'])->middleware('can:access-tenant,tenant')->name('operations.user');
        Route::get('/search/users', [TenantDashboardController::class, 'search'])->middleware('throttle:60,1')->name('search.users');
        Route::get('/notifications', [TenantDashboardController::class, 'notifications'])->name('notifications');
    });
    Route::middleware(['can:tenants.scan', 'can:access-tenant,tenant'])->group(function () {
        Route::post('/tenants/{tenant}/dashboard/refresh', [TenantDashboardController::class, 'refresh'])->name('tenants.dashboard.refresh');
        Route::post('/tenants/{tenant}/dashboard/reports', [TenantDashboardController::class, 'storeReport'])->name('tenants.dashboard.reports.store');
        Route::delete('/tenants/{tenant}/dashboard/reports/{report}', [TenantDashboardController::class, 'destroyReport'])->name('tenants.dashboard.reports.destroy');
    });

    // ---------------- manage tenants ----------------
    Route::middleware('can:tenants.manage')->group(function () {
        Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
        Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::post('/tenants/demo', [TenantController::class, 'storeDemo'])->name('tenants.demo');
        Route::get('/connect/microsoft', [ConsentController::class, 'start'])->name('connect.microsoft');
        Route::middleware('can:access-tenant,tenant')->group(function () {
            Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');
            Route::get('/tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
            Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
            Route::get('/tenants/{tenant}/consent', [TenantController::class, 'consent'])->name('tenants.consent');
            Route::post('/tenants/{tenant}/verify', [TenantController::class, 'verify'])->name('tenants.verify');
        });
    });

    // ---------------- scan ----------------
    Route::middleware('can:tenants.scan')->group(function () {
        Route::post('/tenants/scan-all', [TenantController::class, 'scanAll'])->name('tenants.scan-all');
        Route::post('/tenants/{tenant}/scan', [TenantController::class, 'scan'])->middleware('can:access-tenant,tenant')->name('tenants.scan');
        Route::post('/tenants/{tenant}/drift', [TenantController::class, 'drift'])->middleware('can:access-tenant,tenant')->name('tenants.drift');
    });

    // ---------------- remediation ----------------
    Route::post('/tenants/{tenant}/fix/{check}', [FixController::class, 'store'])->middleware(['can:fixes.apply', 'can:access-tenant,tenant'])->name('fix.store');
    Route::middleware(['can:annotations.manage', 'can:access-tenant,tenant'])->group(function () {
        Route::post('/tenants/{tenant}/annotations/{check}', [AnnotationController::class, 'store'])->name('annotations.store');
        Route::delete('/tenants/{tenant}/annotations/{check}', [AnnotationController::class, 'destroy'])->name('annotations.destroy');
    });
    Route::post('/baselines/{baseline}/apply', [BaselineController::class, 'apply'])->middleware('can:baselines.apply')->name('baselines.apply');
    Route::middleware('can:baselines.manage')->group(function () {
        Route::post('/baselines', [BaselineController::class, 'store'])->name('baselines.store');
        Route::delete('/baselines/{baseline}', [BaselineController::class, 'destroy'])->name('baselines.destroy');
    });

    // ---------------- Intune ----------------
    Route::middleware('can:intune.view')->group(function () {
        Route::get('/intune', [IntuneController::class, 'index'])->name('intune.index');
        Route::get('/intune/bundles/{bundle}/export', [IntuneController::class, 'export'])->name('intune.bundles.export');
        Route::get('/intune/runs/{batch}', [IntuneController::class, 'run'])->whereUuid('batch')->name('intune.run');
        Route::get('/tenants/{tenant}/intune', [TenantIntuneController::class, 'show'])->middleware('can:access-tenant,tenant')->name('tenants.intune');
    });
    Route::middleware('can:intune.manage')->group(function () {
        Route::get('/intune/bundles/create', [IntuneController::class, 'create'])->name('intune.bundles.create');
        Route::post('/intune/bundles', [IntuneController::class, 'store'])->name('intune.bundles.store');
        Route::get('/intune/bundles/{bundle}/edit', [IntuneController::class, 'edit'])->name('intune.bundles.edit');
        Route::put('/intune/bundles/{bundle}', [IntuneController::class, 'update'])->name('intune.bundles.update');
        Route::delete('/intune/bundles/{bundle}', [IntuneController::class, 'destroy'])->name('intune.bundles.destroy');
        Route::post('/intune/bundles/import', [IntuneController::class, 'import'])->name('intune.bundles.import');
    });
    Route::middleware('can:intune.deploy')->group(function () {
        Route::get('/intune/bundles/{bundle}/deploy', [IntuneController::class, 'deployForm'])->name('intune.bundles.deploy');
        Route::post('/intune/bundles/{bundle}/deploy', [IntuneController::class, 'deploy'])->name('intune.bundles.deploy.run');
        Route::post('/tenants/{tenant}/intune/deploy', [TenantIntuneController::class, 'deploy'])->middleware('can:access-tenant,tenant')->name('tenants.intune.deploy');
    });

    // ---------------- Compliance frameworks ----------------
    Route::middleware('can:tenants.view')->group(function () {
        Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
        Route::get('/compliance/frameworks/{key}', [ComplianceController::class, 'framework'])->where('key', '[a-z0-9_]+')->name('compliance.framework');
        Route::get('/tenants/{tenant}/compliance', [ComplianceController::class, 'tenant'])->middleware('can:access-tenant,tenant')->name('compliance.tenant');
    });
    Route::middleware('can:compliance.manage')->group(function () {
        Route::get('/compliance/profiles/create', [ComplianceController::class, 'create'])->name('compliance.profiles.create');
        Route::post('/compliance/profiles', [ComplianceController::class, 'store'])->name('compliance.profiles.store');
        Route::get('/compliance/profiles/{profile}/edit', [ComplianceController::class, 'edit'])->whereNumber('profile')->name('compliance.profiles.edit');
        Route::put('/compliance/profiles/{profile}', [ComplianceController::class, 'update'])->whereNumber('profile')->name('compliance.profiles.update');
        Route::delete('/compliance/profiles/{profile}', [ComplianceController::class, 'destroy'])->whereNumber('profile')->name('compliance.profiles.destroy');
        Route::post('/tenants/{tenant}/compliance/profile', [ComplianceController::class, 'assign'])->middleware('can:access-tenant,tenant')->name('compliance.assign');
    });
    Route::get('/compliance/profiles/{profile}/rollout', [ControlPlanController::class, 'portfolio'])->whereNumber('profile')->middleware('can:tenants.view')->name('compliance.portfolio');
    Route::post('/compliance/profiles/{profile}/rollout', [ControlPlanController::class, 'portfolioApply'])->whereNumber('profile')->middleware('can:fixes.apply')->name('compliance.portfolio.apply');
    Route::get('/compliance/profiles/{profile}/export', [ComplianceController::class, 'export'])->whereNumber('profile')->middleware('can:tenants.view')->name('compliance.profiles.export');
    Route::post('/compliance/profiles/import', [ComplianceController::class, 'import'])->middleware('can:compliance.manage')->name('compliance.profiles.import');
    Route::get('/tenants/{tenant}/secure-score', [SecureScoreController::class, 'show'])->middleware(['can:tenants.view', 'can:access-tenant,tenant'])->name('tenants.securescore');
    Route::get('/tenants/{tenant}/plan', [ControlPlanController::class, 'rollout'])->middleware(['can:tenants.view', 'can:access-tenant,tenant'])->name('plans.rollout');
    Route::post('/tenants/{tenant}/plan/apply', [ControlPlanController::class, 'apply'])->middleware(['can:fixes.apply', 'can:access-tenant,tenant'])->name('plans.apply');
    Route::middleware(['can:compliance.attest', 'can:access-tenant,tenant'])->group(function () {
        Route::post('/tenants/{tenant}/compliance/{framework}/attest/{requirement}', [ComplianceController::class, 'attest'])->where('requirement', '.+')->name('compliance.attest');
        Route::delete('/tenants/{tenant}/compliance/{framework}/attest/{requirement}', [ComplianceController::class, 'unattest'])->where('requirement', '.+')->name('compliance.unattest');
    });

    // ---------------- Identity management: onboarding, offboarding, templates ----------------
    Route::pattern('run', '[0-9]+');
    Route::pattern('task', '[0-9]+');
    Route::pattern('template', '[0-9]+');
    Route::get('/identity/start/{action}', [OperationsController::class, 'pick'])->where('action', 'users|groups|guests|onboard|bulk|offboard|templates')->name('identity.pick');
    Route::get('/identity/templates', [UserTemplateController::class, 'index'])->middleware('can:staff.onboard')->name('templates.index');
    Route::get('/operations', [OperationsController::class, 'index'])->middleware('can:tenants.view')->name('operations.index');
    Route::get('/operations/runs/{run}', [OperationsController::class, 'run'])->middleware('can:tenants.view')->name('operations.run');
    Route::post('/operations/runs/{run}/steps/{key}', [OperationsController::class, 'step'])->name('operations.step');
    Route::post('/operations/tasks/{task}/cancel', [OperationsController::class, 'cancelTask'])->name('operations.task.cancel');
    Route::middleware('can:access-tenant,tenant')->group(function () {
        Route::get('/tenants/{tenant}/people', [OperationsController::class, 'tenant'])->middleware('can:tenants.view')->name('operations.tenant');
        Route::get('/tenants/{tenant}/people/onboard', [OperationsController::class, 'onboardForm'])->middleware('can:staff.onboard')->name('operations.onboard');
        Route::post('/tenants/{tenant}/people/onboard', [OperationsController::class, 'onboard'])->middleware('can:staff.onboard')->name('operations.onboard.run');
        Route::get('/tenants/{tenant}/people/offboard', [OperationsController::class, 'offboardForm'])->middleware('can:staff.offboard')->name('operations.offboard');
        Route::post('/tenants/{tenant}/people/offboard', [OperationsController::class, 'offboard'])->middleware('can:staff.offboard')->name('operations.offboard.run');
        Route::get('/tenants/{tenant}/identity/users', [IdentityDirectoryController::class, 'users'])->middleware('can:tenants.view')->name('identity.users');
        Route::get('/tenants/{tenant}/identity/guests', [IdentityDirectoryController::class, 'guests'])->middleware('can:tenants.view')->name('identity.guests');
        Route::get('/tenants/{tenant}/identity/groups', [IdentityDirectoryController::class, 'groups'])->middleware('can:tenants.view')->name('identity.groups');
        Route::get('/tenants/{tenant}/identity/groups/{group}', [IdentityDirectoryController::class, 'group'])->middleware('can:tenants.view')->name('identity.group');
        Route::post('/tenants/{tenant}/identity/refresh', [IdentityDirectoryController::class, 'refresh'])->middleware('can:tenants.view')->name('identity.refresh');
        Route::post('/tenants/{tenant}/identity/guests/remove', [IdentityDirectoryController::class, 'removeGuests'])->middleware(['can:staff.offboard', 'throttle:10,1'])->name('identity.guests.remove');
        Route::get('/tenants/{tenant}/people/bulk', [OperationsController::class, 'bulkForm'])->middleware('can:staff.onboard')->name('operations.bulk');
        Route::post('/tenants/{tenant}/people/bulk/preview', [OperationsController::class, 'bulkPreview'])->middleware('can:staff.onboard')->name('operations.bulk.preview');
        Route::post('/tenants/{tenant}/people/bulk', [OperationsController::class, 'bulk'])->middleware('can:staff.onboard')->name('operations.bulk.run');
        Route::delete('/tenants/{tenant}/people/templates/{template}', [OperationsController::class, 'deleteTemplate'])->middleware('can:staff.onboard')->name('operations.template.delete');
        Route::middleware('can:staff.onboard')->group(function () {
            Route::get('/tenants/{tenant}/templates/create', [UserTemplateController::class, 'create'])->name('templates.create');
            Route::post('/tenants/{tenant}/templates', [UserTemplateController::class, 'store'])->name('templates.store');
            Route::get('/tenants/{tenant}/templates/{template}/edit', [UserTemplateController::class, 'edit'])->name('templates.edit');
            Route::put('/tenants/{tenant}/templates/{template}', [UserTemplateController::class, 'update'])->name('templates.update');
            Route::delete('/tenants/{tenant}/templates/{template}', [UserTemplateController::class, 'destroy'])->name('templates.destroy');
        });
    });

    // ---------------- Data security: Microsoft Purview programmes ----------------
    Route::pattern('project', '[0-9]+');
    Route::pattern('ptask', '[0-9]+');
    Route::middleware('can:purview.view')->group(function () {
        Route::get('/purview', [PurviewController::class, 'index'])->name('purview.index');
        Route::get('/purview/{project}', [PurviewController::class, 'show'])->name('purview.show');
        Route::get('/purview/{project}/requirements', [PurviewController::class, 'requirements'])->name('purview.requirements');
        Route::get('/purview/{project}/design', [PurviewController::class, 'design'])->name('purview.design');
        Route::get('/purview/{project}/plan', [PurviewController::class, 'plan'])->name('purview.plan');
        Route::get('/purview/{project}/implement', [PurviewController::class, 'implement'])->name('purview.implement');
        Route::get('/purview/{project}/documents/{doc}', [PurviewController::class, 'document'])->name('purview.document');
    });
    Route::middleware('can:purview.manage')->group(function () {
        Route::post('/purview', [PurviewController::class, 'start'])->name('purview.start');
        Route::post('/purview/{project}/requirements', [PurviewController::class, 'saveRequirements'])->name('purview.requirements.save');
        Route::post('/purview/{project}/design', [PurviewController::class, 'updateDesign'])->name('purview.design.update');
        Route::post('/purview/{project}/tasks/{task}', [PurviewController::class, 'updateTask'])->name('purview.task.update');
        Route::post('/purview/{project}/tasks/{task}/run', [PurviewController::class, 'runTask'])->name('purview.task.run');
        Route::get('/purview/{project}/scripts/{phase}', [PurviewController::class, 'script'])->name('purview.script');
        Route::post('/purview/{project}/verify', [PurviewController::class, 'uploadVerification'])->name('purview.verify');
        Route::post('/purview/{project}/reverify', [PurviewController::class, 'reverify'])->name('purview.reverify');
    });
    Route::middleware('can:purview.approve')->group(function () {
        Route::post('/purview/{project}/approve', [PurviewController::class, 'approve'])->name('purview.approve');
        Route::post('/purview/{project}/signoff/{gate}', [PurviewController::class, 'signoff'])->where('gate', 'discover|pilot|production')->name('purview.signoff');
    });

    // ---------------- Microsoft 365 Copilot programmes ----------------
    Route::pattern('cproject', '[0-9]+');
    Route::middleware('can:copilot.view')->group(function () {
        Route::get('/copilot', [CopilotController::class, 'index'])->name('copilot.index');
        Route::get('/copilot/{project}', [CopilotController::class, 'show'])->name('copilot.show');
        Route::get('/copilot/{project}/requirements', [CopilotController::class, 'requirements'])->name('copilot.requirements');
        Route::get('/copilot/{project}/plan', [CopilotController::class, 'plan'])->name('copilot.plan');
        Route::get('/copilot/{project}/implement', [CopilotController::class, 'implement'])->name('copilot.implement');
        Route::get('/copilot/{project}/documents/{doc}', [CopilotController::class, 'document'])->name('copilot.document');
    });
    Route::middleware('can:copilot.manage')->group(function () {
        Route::post('/copilot', [CopilotController::class, 'start'])->name('copilot.start');
        Route::post('/copilot/{project}/requirements', [CopilotController::class, 'saveRequirements'])->name('copilot.requirements.save');
        Route::post('/copilot/{project}/assess', [CopilotController::class, 'assess'])->name('copilot.assess');
        Route::post('/copilot/{project}/tasks/{task}', [CopilotController::class, 'updateTask'])->name('copilot.task.update');
        Route::post('/copilot/{project}/tasks/{task}/run', [CopilotController::class, 'runTask'])->name('copilot.task.run');
        Route::post('/copilot/{project}/licences', [CopilotController::class, 'licences'])->middleware('throttle:20,1')->name('copilot.licences');
        Route::post('/copilot/{project}/groups', [CopilotController::class, 'groups'])->name('copilot.groups');
        Route::post('/copilot/{project}/usage', [CopilotController::class, 'usage'])->name('copilot.usage');
        Route::get('/copilot/{project}/scripts/{phase}', [CopilotController::class, 'script'])->name('copilot.script');
    });
    Route::post('/copilot/{project}/signoff/{gate}', [CopilotController::class, 'signoff'])->middleware('can:copilot.approve')->where('gate', 'readiness|pilot|rollout')->name('copilot.signoff');

    // ---------------- Tools: PowerShell scripts ----------------
    Route::pattern('script', '[0-9]+');
    Route::pattern('version', '[0-9]+');
    Route::pattern('scriptRun', '[0-9]+');
    Route::middleware('can:scripts.view')->group(function () {
        Route::get('/scripts', [ScriptController::class, 'index'])->name('scripts.index');
        Route::get('/scripts/{script}', [ScriptController::class, 'show'])->name('scripts.show');
        Route::get('/scripts/runs', [ScriptController::class, 'runs'])->name('scripts.runs');
        Route::get('/scripts/runs/{run}', [ScriptController::class, 'showRun'])->name('scripts.runs.show');
        Route::get('/scripts/runs/{run}/status', [ScriptController::class, 'status'])->name('scripts.runs.status');
        Route::get('/scripts/runs/{run}/export.{format}', [ScriptController::class, 'export'])->where('format', 'csv|json')->name('scripts.runs.export');
        Route::post('/scripts/runs/{run}/cancel', [ScriptController::class, 'cancel'])->name('scripts.runs.cancel');
        Route::post('/scripts/policy', [ScriptController::class, 'policy'])->name('scripts.policy');
    });
    Route::middleware('can:scripts.manage')->group(function () {
        Route::get('/scripts/create', [ScriptController::class, 'create'])->name('scripts.create');
        Route::post('/scripts', [ScriptController::class, 'store'])->name('scripts.store');
        Route::get('/scripts/{script}/edit', [ScriptController::class, 'edit'])->name('scripts.edit');
        Route::put('/scripts/{script}', [ScriptController::class, 'update'])->name('scripts.update');
        Route::post('/scripts/{script}/versions/{version}/submit', [ScriptController::class, 'submit'])->name('scripts.submit');
        Route::post('/scripts/{script}/archive', [ScriptController::class, 'archive'])->name('scripts.archive');
    });
    Route::post('/scripts/{script}/versions/{version}/review', [ScriptController::class, 'review'])->middleware('can:scripts.approve')->name('scripts.review');
    // Run permissions (read vs change) are checked in the controller against the approved version.
    Route::get('/scripts/{script}/run', [ScriptController::class, 'runForm'])->middleware('can:scripts.view')->name('scripts.run');
    Route::post('/scripts/{script}/run', [ScriptController::class, 'run'])->middleware(['can:scripts.view', 'throttle:10,1'])->name('scripts.run.store');

    // ---------------- reporting ----------------
    Route::middleware(['can:reports.view', 'can:access-tenant,tenant'])->group(function () {
        Route::get('/tenants/{tenant}/report', [ReportController::class, 'show'])->name('reports.show');
        Route::get('/tenants/{tenant}/report.xlsx', [ReportController::class, 'excel'])->name('reports.excel');
    });
    Route::get('/activity', [ActivityController::class, 'index'])->middleware('can:activity.view')->name('activity.index');
    Route::get('/activity/export.csv', [ActivityController::class, 'export'])->middleware('can:activity.view')->name('activity.export');

    // ---------------- permission scope (app plan, client access profiles, grants) ----------------
    Route::get('/access', [AccessController::class, 'index'])->middleware('can:tenants.view')->name('access.index');
    Route::post('/access/plan', [AccessController::class, 'plan'])->middleware('can:settings.manage')->name('access.plan');
    Route::middleware('can:access-tenant,tenant')->group(function () {
        Route::get('/tenants/{tenant}/access', [AccessController::class, 'show'])->middleware('can:tenants.view')->name('access.tenant');
        Route::middleware('can:tenants.manage')->group(function () {
            Route::post('/tenants/{tenant}/access', [AccessController::class, 'update'])->name('access.update');
            Route::post('/tenants/{tenant}/access/check', [AccessController::class, 'check'])->middleware('throttle:10,1')->name('access.check');
            Route::get('/tenants/{tenant}/access/script', [AccessController::class, 'script'])->name('access.script');
            Route::post('/tenants/{tenant}/access/simulate', [AccessController::class, 'simulate'])->name('access.simulate');
            Route::get('/tenants/{tenant}/access/consent', [AccessController::class, 'consent'])->name('access.consent');
        });
    });

    // ---------------- administration ----------------
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/notifications', [SettingsController::class, 'notifications'])->name('settings.notifications');
        Route::post('/settings/microsoft/setup/start', [MicrosoftSetupController::class, 'start'])->name('ms-setup.start');
        Route::post('/settings/microsoft/setup/poll', [MicrosoftSetupController::class, 'poll'])->name('ms-setup.poll');
        Route::post('/settings/microsoft/manual', [MicrosoftSetupController::class, 'manual'])->name('ms-setup.manual');
        Route::delete('/settings/microsoft', [MicrosoftSetupController::class, 'forget'])->name('ms-setup.forget');
        Route::post('/settings/microsoft/signin', [MicrosoftSetupController::class, 'signin'])->name('ms-setup.signin');
    });

    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });
});
