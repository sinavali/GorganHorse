<?php
declare(strict_types=1);

/**
 * File: app/Http/routes.php
 *
 * Purpose:
 *   The single route table for the panel (Blueprint §10, Technical §5.1).
 *   Each entry is [METHOD, PATH, [Controller::class, method], [middleware...]].
 *   Path parameters use `{param}` syntax and become request attributes.
 *
 * Middleware keys:
 *   - guest          : no auth required
 *   - auth           : authentication required (default when no 'guest')
 *   - role:a,b       : allowed roles
 *   - csrf           : CSRF token required (all state-changing requests)
 *   - rate:login     : route-specific rate limiting hint
 *
 * @package App\Http
 */

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\SmsTemplateController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\CompetitionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HorseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\RadeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\UserController;

return [
    // ---------------- Public / guest ----------------
    ['GET',  '/',                     [AuthController::class, 'loginForm'],   ['guest']],
    ['GET',  '/install',              [\App\Http\Controllers\InstallController::class, 'index'], ['guest']],
    ['POST', '/install/run',          [\App\Http\Controllers\InstallController::class, 'run'],   ['guest', 'csrf']],

    ['GET',  '/auth/login',           [AuthController::class, 'loginForm'],   ['guest']],
    ['POST', '/auth/login',           [AuthController::class, 'login'],       ['guest', 'csrf', 'rate:login']],
    ['POST', '/auth/login/otp/request', [AuthController::class, 'requestOtp'], ['guest', 'csrf', 'rate:otp']],
    ['POST', '/auth/login/otp/verify', [AuthController::class, 'verifyOtp'],  ['guest', 'csrf', 'rate:otp']],
    ['GET',  '/auth/signup',          [AuthController::class, 'signupForm'],  ['guest']],
    ['POST', '/auth/signup',          [AuthController::class, 'signup'],      ['guest', 'csrf', 'rate:signup']],
    ['GET',  '/auth/forgot',          [AuthController::class, 'forgot'],      ['guest']],
    ['POST', '/auth/logout',          [AuthController::class, 'logout'],      ['guest', 'csrf']],
    ['GET',  '/captcha/{token}',      [AuthController::class, 'captcha'],     ['guest']],
    ['POST', '/captcha/issue',        [AuthController::class, 'issueCaptcha'], ['guest', 'csrf']],

    // ---------------- Payment callbacks (public) ----------------
    ['GET',  '/payment/callback',     [PaymentController::class, 'callback'],  ['guest']],
    ['GET',  '/payment/success',      [PaymentController::class, 'success'],   ['guest']],
    ['GET',  '/payment/failed',       [PaymentController::class, 'failed'],    ['guest']],

    // ---------------- Dashboard ----------------
    ['GET',  '/panel',                [DashboardController::class, 'index'],   ['auth']],
    ['GET',  '/panel/dashboard/data', [DashboardController::class, 'data'],    ['auth']],

    // ---------------- Profile ----------------
    ['GET',  '/panel/profile',        [UserController::class, 'profile'],      ['auth']],
    ['POST', '/panel/profile',        [UserController::class, 'updateProfile'], ['auth', 'csrf']],
    ['POST', '/panel/profile/avatar', [UserController::class, 'uploadAvatar'], ['auth', 'csrf']],
    ['GET',  '/panel/profile/sessions', [UserController::class, 'sessions'],   ['auth']],
    ['POST', '/panel/profile/password', [UserController::class, 'changePassword'], ['auth', 'csrf']],
    ['POST', '/panel/profile/sessions/{id}/revoke', [UserController::class, 'revokeOwnSession'], ['auth', 'csrf']],
    ['POST', '/panel/profile/sessions/revoke-all', [UserController::class, 'revokeOwnSessions'], ['auth', 'csrf']],

    // ---------------- Users ----------------
    ['GET',  '/panel/users',          [UserController::class, 'index'],        ['auth', 'role:admin,manager']],
    ['POST', '/panel/users',          [UserController::class, 'store'],        ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/users/bulk',     [UserController::class, 'bulk'],         ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/users/{id}',     [UserController::class, 'show'],         ['auth']],
    ['PUT',  '/panel/users/{id}',     [UserController::class, 'update'],       ['auth', 'csrf']],
    ['DELETE', '/panel/users/{id}',   [UserController::class, 'destroy'],      ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/users/{id}/verify', [UserController::class, 'verify'],    ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/users/{id}/reject', [UserController::class, 'reject'],    ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/users/{id}/disable', [UserController::class, 'disable'],  ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/users/{id}/enable', [UserController::class, 'enable'],    ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/users/{id}/reset-password', [UserController::class, 'resetPassword'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/users/{id}/impersonate', [UserController::class, 'impersonate'], ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/users/{id}/sessions/revoke-all', [UserController::class, 'revokeAllSessions'], ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/impersonation/stop', [UserController::class, 'stopImpersonation'], ['auth', 'csrf']],

    // ---------------- Clubs ----------------
    ['GET',  '/panel/clubs',          [ClubController::class, 'index'],        ['auth']],
    ['POST', '/panel/clubs',          [ClubController::class, 'store'],        ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/clubs/{id}',     [ClubController::class, 'show'],         ['auth']],
    ['PUT',  '/panel/clubs/{id}',     [ClubController::class, 'update'],       ['auth', 'csrf']],
    ['DELETE', '/panel/clubs/{id}',   [ClubController::class, 'destroy'],      ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/clubs/{id}/bans', [ClubController::class, 'addBan'],      ['auth', 'csrf']],
    ['DELETE', '/panel/clubs/{id}/bans/{ban_id}', [ClubController::class, 'removeBan'], ['auth', 'csrf']],
    ['GET',  '/panel/clubs/{id}/print', [ClubController::class, 'print'],      ['auth']],

    // ---------------- Horses ----------------
    ['GET',  '/panel/horses',         [HorseController::class, 'index'],       ['auth']],
    ['POST', '/panel/horses',         [HorseController::class, 'store'],       ['auth', 'csrf']],
    ['POST', '/panel/horses/bulk',    [HorseController::class, 'bulk'],        ['auth', 'csrf']],
    ['POST', '/panel/horses/import',  [HorseController::class, 'import'],      ['auth', 'csrf']],
    ['GET',  '/panel/horses/export-template', [HorseController::class, 'exportTemplate'], ['auth']],
    ['POST', '/panel/horses/export',  [HorseController::class, 'export'],      ['auth', 'csrf']],
    ['GET',  '/panel/horses/{id}',    [HorseController::class, 'show'],        ['auth']],
    ['PUT',  '/panel/horses/{id}',    [HorseController::class, 'update'],      ['auth', 'csrf']],
    ['DELETE', '/panel/horses/{id}',  [HorseController::class, 'destroy'],     ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/sold-to-non-rider', [HorseController::class, 'soldToNonRider'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/images', [HorseController::class, 'addImage'], ['auth', 'csrf']],
    ['DELETE', '/panel/horses/{id}/images/{media_id}', [HorseController::class, 'removeImage'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/share', [HorseController::class, 'share'],    ['auth', 'csrf']],
    ['DELETE', '/panel/horses/{id}/share/{share_id}', [HorseController::class, 'revokeShare'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/transfer/lock', [HorseController::class, 'lockTransfer'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/transfer/unlock', [HorseController::class, 'unlockTransfer'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/transfer/initiate', [HorseController::class, 'initiateTransfer'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/transfer/claim', [HorseController::class, 'claimTransfer'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/transfer/accept', [HorseController::class, 'acceptTransfer'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/transfer/reject', [HorseController::class, 'rejectTransfer'], ['auth', 'csrf']],
    ['POST', '/panel/horses/{id}/transfer/cancel', [HorseController::class, 'cancelTransfer'], ['auth', 'csrf']],
    ['GET',  '/panel/horses/{id}/history', [HorseController::class, 'history'], ['auth']],
    ['GET',  '/panel/horses/{id}/print', [HorseController::class, 'print'],    ['auth']],

    // ---------------- Horse shares inbox ----------------
    ['GET',  '/panel/horse-shares',   [HorseController::class, 'sharesInbox'], ['auth']],
    ['POST', '/panel/horse-shares/{id}/accept', [HorseController::class, 'respondShare'], ['auth', 'csrf']],
    ['POST', '/panel/horse-shares/{id}/reject', [HorseController::class, 'respondShare'], ['auth', 'csrf']],

    // ---------------- Rades ----------------
    ['GET',  '/panel/rades',          [RadeController::class, 'index'],        ['auth']],
    ['POST', '/panel/rades',          [RadeController::class, 'store'],        ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/rades/bulk',     [RadeController::class, 'bulk'],         ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/rades/{id}',     [RadeController::class, 'show'],         ['auth']],
    ['PUT',  '/panel/rades/{id}',     [RadeController::class, 'update'],       ['auth', 'role:admin,manager', 'csrf']],
    ['DELETE', '/panel/rades/{id}',   [RadeController::class, 'destroy'],      ['auth', 'role:admin,manager', 'csrf']],

    // ---------------- Payments (templates) ----------------
    ['GET',  '/panel/payments',       [PaymentController::class, 'index'],     ['auth']],
    ['POST', '/panel/payments',       [PaymentController::class, 'store'],     ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/payments/bulk',  [PaymentController::class, 'bulk'],      ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/payments/print-list', [PaymentController::class, 'printList'], ['auth']],
    ['GET',  '/panel/payments/{id}',  [PaymentController::class, 'show'],      ['auth']],
    ['PUT',  '/panel/payments/{id}',  [PaymentController::class, 'update'],    ['auth', 'role:admin,manager', 'csrf']],
    ['DELETE', '/panel/payments/{id}', [PaymentController::class, 'destroy'],  ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/payments/{id}/print', [PaymentController::class, 'print'], ['auth']],

    // ---------------- Competitions ----------------
    ['GET',  '/panel/competitions',   [CompetitionController::class, 'index'], ['auth']],
    ['POST', '/panel/competitions',   [CompetitionController::class, 'store'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/bulk', [CompetitionController::class, 'bulk'], ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/competitions/{id}', [CompetitionController::class, 'show'], ['auth']],
    ['PUT',  '/panel/competitions/{id}', [CompetitionController::class, 'update'], ['auth', 'role:admin,manager', 'csrf']],
    ['DELETE', '/panel/competitions/{id}', [CompetitionController::class, 'destroy'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/pause', [CompetitionController::class, 'pause'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/resume', [CompetitionController::class, 'resume'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/cancel', [CompetitionController::class, 'cancel'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/clone', [CompetitionController::class, 'clone'], ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/competitions/{id}/print', [CompetitionController::class, 'print'], ['auth']],
    ['GET',  '/panel/competitions/{id}/signup-sheet/print', [CompetitionController::class, 'printSignupSheet'], ['auth']],
    ['POST', '/panel/competitions/{id}/rades', [CompetitionController::class, 'addRade'], ['auth', 'role:admin,manager', 'csrf']],
    ['PUT',  '/panel/competitions/{id}/rades/{comp_rade_id}', [CompetitionController::class, 'updateRade'], ['auth', 'role:admin,manager', 'csrf']],
    ['DELETE', '/panel/competitions/{id}/rades/{comp_rade_id}', [CompetitionController::class, 'removeRade'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/rades/{comp_rade_id}/barrage', [CompetitionController::class, 'barrage'], ['auth', 'role:admin,manager', 'csrf']],

    // ---------------- Signups ----------------
    ['GET',  '/panel/signups',        [SignupController::class, 'index'],      ['auth', 'role:admin,manager']],
    ['POST', '/panel/signups/bulk',   [SignupController::class, 'bulk'],       ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/signups/{id}',   [SignupController::class, 'show'],       ['auth']],
    ['POST', '/panel/signups/{id}/confirm', [SignupController::class, 'confirm'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/signups/{id}/reject', [SignupController::class, 'reject'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/signups/{id}/position', [SignupController::class, 'position'], ['auth', 'role:admin,manager', 'csrf']],

    // ---------------- Rider signup flow ----------------
    ['GET',  '/panel/rider/competitions', [CompetitionController::class, 'riderIndex'], ['auth']],
    ['GET',  '/panel/rider/competitions/{id}', [CompetitionController::class, 'riderShow'], ['auth']],
    ['POST', '/panel/rider/competitions/{id}/signup', [CompetitionController::class, 'riderSignup'], ['auth', 'csrf']],
    ['GET',  '/panel/rider/signups', [SignupController::class, 'riderIndex'], ['auth']],
    ['GET',  '/panel/rider/signups/{id}', [SignupController::class, 'riderShow'], ['auth']],

    // ---------------- Payment orders ----------------
    ['GET',  '/panel/payment-orders', [PaymentController::class, 'orders'],    ['auth']],
    ['POST', '/panel/payment-orders/bulk', [PaymentController::class, 'bulkOrders'], ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/payment-orders/reconciliation', [PaymentController::class, 'reconciliation'], ['auth', 'role:admin,manager']],
    ['POST', '/panel/payment-orders/reconciliation', [PaymentController::class, 'reconciliation'], ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/payment-orders/{id}', [PaymentController::class, 'showOrder'], ['auth']],
    ['POST', '/panel/payment-orders/{id}/verify', [PaymentController::class, 'verifyOrder'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/payment-orders/{id}/mark-pending-refund', [PaymentController::class, 'markPendingRefund'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/payment-orders/{id}/mark-refunded', [PaymentController::class, 'markRefunded'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/payment-orders/{id}/unmark-refund', [PaymentController::class, 'unmarkRefund'], ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/payment-orders/{id}/print', [PaymentController::class, 'printOrder'], ['auth']],

    // ---------------- Results ----------------
    ['GET',  '/panel/competitions/{id}/results', [ResultController::class, 'index'], ['auth', 'role:admin,manager']],
    ['POST', '/panel/competitions/{id}/results', [ResultController::class, 'save'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/results/confirm', [ResultController::class, 'confirm'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/results/publish', [ResultController::class, 'publish'], ['auth', 'role:admin,manager', 'csrf']],
    ['POST', '/panel/competitions/{id}/results/reopen', [ResultController::class, 'reopen'], ['auth', 'role:admin', 'csrf']],
    ['GET',  '/panel/standings/print', [ResultController::class, 'printStandings'], ['auth']],

    // ---------------- Reports ----------------
    ['GET',  '/panel/reports',        [ReportController::class, 'index'],      ['auth']],
    ['POST', '/panel/reports/data',   [ReportController::class, 'data'],       ['auth', 'csrf']],
    ['POST', '/panel/reports/export', [ReportController::class, 'export'],     ['auth', 'csrf']],
    ['GET',  '/panel/reports/download/{token}', [ReportController::class, 'download'], ['auth']],
    ['POST', '/panel/reports/share',  [ReportController::class, 'share'],      ['auth', 'csrf']],
    ['GET',  '/panel/reports/shares', [ReportController::class, 'shares'],     ['auth']],
    ['POST', '/panel/reports/shares/{id}/revoke', [ReportController::class, 'revokeShare'], ['auth', 'csrf']],

    // ---------------- Notifications & messages ----------------
    ['GET',  '/panel/notifications',  [NotificationController::class, 'index'], ['auth']],
    ['POST', '/panel/notifications/{id}/read', [NotificationController::class, 'read'], ['auth', 'csrf']],
    ['POST', '/panel/notifications/read-all', [NotificationController::class, 'readAll'], ['auth', 'csrf']],
    ['GET',  '/panel/messages',       [NotificationController::class, 'messages'], ['auth']],
    ['POST', '/panel/messages',       [NotificationController::class, 'send'],  ['auth', 'role:admin,manager', 'csrf']],
    ['GET',  '/panel/messages/{id}',  [NotificationController::class, 'showMessage'], ['auth']],
    ['POST', '/panel/messages/{id}/read', [NotificationController::class, 'readMessage'], ['auth', 'csrf']],

    // ---------------- Settings ----------------
    ['GET',  '/panel/settings',       [SettingsController::class, 'index'],    ['auth', 'role:admin']],
    ['POST', '/panel/settings',       [SettingsController::class, 'index'],    ['auth', 'role:admin', 'csrf']],
    ['GET',  '/panel/settings/sms',   [SettingsController::class, 'sms'],      ['auth', 'role:admin']],
    ['POST', '/panel/settings/sms',   [SettingsController::class, 'sms'],      ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/settings/sms/test', [SettingsController::class, 'smsTest'], ['auth', 'role:admin', 'csrf']],

    // ---------------- SMS Templates & Logs ----------------
    ['GET',  '/panel/sms/templates/{id}', [SmsTemplateController::class, 'show'],      ['auth', 'role:admin']],
    ['GET',  '/panel/sms/templates',      [SmsTemplateController::class, 'templates'], ['auth', 'role:admin']],
    ['POST', '/panel/sms/templates',      [SmsTemplateController::class, 'templates'], ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/sms/templates/{id}', [SmsTemplateController::class, 'update'],    ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/sms/templates/{id}/toggle', [SmsTemplateController::class, 'toggle'], ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/sms/templates/{id}/delete', [SmsTemplateController::class, 'delete'], ['auth', 'role:admin', 'csrf']],
    ['GET',  '/panel/sms/log',            [SmsTemplateController::class, 'log'],         ['auth', 'role:admin']],

    // ---------------- API SMS ----------------
    ['POST', '/api/sms/send', [SmsController::class, 'send'], ['auth', 'role:admin,manager']],
    ['GET',  '/panel/settings/payment', [SettingsController::class, 'payment'], ['auth', 'role:admin']],
    ['POST', '/panel/settings/payment', [SettingsController::class, 'payment'], ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/cache/clear',    [SettingsController::class, 'clearCache'], ['auth', 'role:admin', 'csrf']],

    // ---------------- Audit ----------------
    ['GET',  '/panel/audit',          [AdminController::class, 'audit'],       ['auth', 'role:admin']],

    // ---------------- Backups & maintenance ----------------
    ['GET',  '/panel/backups',        [AdminController::class, 'backups'],     ['auth', 'role:admin']],
    ['POST', '/panel/backups',        [AdminController::class, 'createBackup'], ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/backups/{name}/restore', [AdminController::class, 'restore'], ['auth', 'role:admin', 'csrf']],
    ['GET',  '/panel/backups/{name}/download', [AdminController::class, 'downloadBackup'], ['auth', 'role:admin']],
    ['DELETE', '/panel/backups/{name}', [AdminController::class, 'deleteBackup'], ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/reset',          [AdminController::class, 'reset'],       ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/demo/seed',      [AdminController::class, 'seedDemo'],    ['auth', 'role:admin', 'csrf']],
    ['POST', '/panel/demo/clear',     [AdminController::class, 'clearDemo'],   ['auth', 'role:admin', 'csrf']],
    ['GET',  '/panel/qr',             [AdminController::class, 'qr'],          ['auth']],

    // ---------------- Generic print ----------------
    ['GET',  '/panel/print/{entity}/{id}', [PrintController::class, 'show'],   ['auth']],
];
