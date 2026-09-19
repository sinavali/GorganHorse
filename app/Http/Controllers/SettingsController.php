<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/SettingsController.php
 *
 * Purpose:
 *   HTTP layer for settings (general, SMS, payment, cache) — Admin only
 *   (Blueprint §15; User Usage §7.21).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: SettingsController
 * Purpose: Handle settings endpoints.
 */
final class SettingsController extends BaseController
{
    /**
     * Show settings (grouped) or update them.
     *
     * Route:   GET|POST /panel/settings
     * Auth:    role:admin
     * Returns: HTML (GET) or JSON envelope { data: null } (POST)
     */
    public function index(Request $request, MiddlewareContext $ctx): Response
    {
        $settings = $this->c->get('settings');
        if ($request->isMethod('POST')) {
            $input = $this->input($request);
            $settings->setMany($this->normalizeBooleans($input));
            $this->c->get('log')->audit(['actor_id' => (int) $ctx->user['id'], 'actor_role' => 'admin', 'action' => 'settings.update', 'target_type' => 'settings', 'target_id' => 0, 'diff' => array_keys($input)]);
            return $this->ok(null, $ctx);
        }
        if ($request->isJson()) { return $this->ok($settings->grouped(), $ctx); }
        return $this->view('panel/settings', ['groups' => $settings->grouped(), 'csrf' => $ctx->csrf]);
    }

    /**
     * SMS settings page / update.
     *
     * Route:   GET|POST /panel/settings/sms
     * Auth:    role:admin
     * Returns: HTML or JSON
     */
    public function sms(Request $request, MiddlewareContext $ctx): Response
    {
        $settings = $this->c->get('settings');
        if ($request->isMethod('POST')) {
            $settings->setMany($this->normalizeBooleans($this->input($request)));
            return $this->ok(null, $ctx);
        }
        $groups = $settings->grouped();
        return $this->view('panel/settings', ['groups' => ['sms' => $groups['sms'] ?? [], 'general' => [], 'whitelabel' => [], 'auth' => [], 'uploads' => [], 'clubs' => [], 'horses' => [], 'competitions' => [], 'payments' => [], 'reports' => [], 'cache' => [], 'logs' => [], 'backup' => [], 'security' => [], 'ui' => [], 'identity' => []], 'csrf' => $ctx->csrf, 'active' => 'sms']);
    }

    /**
     * Send a test SMS.
     *
     * Route:   POST /panel/settings/sms/test
     * Auth:    role:admin
     * Returns: JSON envelope { data: { sent } }
     */
    public function smsTest(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $phone = (string) ($input['phone'] ?? '');
        $sent = $this->c->get('sms')->send($phone, 'پیام آزمایشی سامانه سوارکاری گلستان');
        return $this->ok(['sent' => $sent], $ctx);
    }

    /**
     * Payment settings page / update.
     *
     * Route:   GET|POST /panel/settings/payment
     * Auth:    role:admin
     * Returns: HTML or JSON
     */
    public function payment(Request $request, MiddlewareContext $ctx): Response
    {
        $settings = $this->c->get('settings');
        if ($request->isMethod('POST')) {
            $settings->setMany($this->normalizeBooleans($this->input($request)));
            return $this->ok(null, $ctx);
        }
        $groups = $settings->grouped();
        return $this->view('panel/settings', ['groups' => ['payments' => $groups['payments'] ?? []], 'csrf' => $ctx->csrf, 'active' => 'payments']);
    }

    /**
     * Clear the cache (all namespaces).
     *
     * Route:   POST /panel/cache/clear
     * Auth:    role:admin
     * Returns: JSON envelope { data: null }
     */
    public function clearCache(Request $request, MiddlewareContext $ctx): Response
    {
        $this->c->get('cache')->clearAll();
        $this->c->get('log')->audit(['actor_id' => (int) $ctx->user['id'], 'actor_role' => 'admin', 'action' => 'cache.clear', 'target_type' => 'cache', 'target_id' => 0]);
        return $this->ok(null, $ctx);
    }

    /**
     * Convert checkbox-style values to booleans for known bool settings.
     *
     * @param array $input Raw input.
     * @return array
     */
    private function normalizeBooleans(array $input): array
    {
        $registry = \App\Services\SettingService::registry();
        $out = [];
        foreach ($input as $key => $value) {
            if (($registry[$key]['type'] ?? '') === 'bool') {
                $out[$key] = in_array($value, [1, '1', 'true', 'on', true], true);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
