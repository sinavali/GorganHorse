<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/SettingsController.php
 *
 * Purpose:
 *   HTTP layer for settings (general, SMS, payment, cache) — Admin only
 *   (Blueprint §15; User Usage §7.21). JSON API only.
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
     * Host requirement checklist (PHP version, extensions, writable folders).
     *
     * Route:   GET /panel/requirements
     * Auth:    guest (the installer wizard runs before any account exists)
     * Returns: JSON envelope { data: { installed, requirements[] } }
     *
     * The installer wizard is served as an SPA page at /install; this is the
     * only JSON endpoint behind it.
     */
    public function requirements(Request $request, MiddlewareContext $ctx): Response
    {
        $installer = $this->c->get('installer');
        return $this->ok([
            'installed' => (bool) $ctx->settings->get('app.installed', false),
            'requirements' => $installer->requirements(),
        ], $ctx);
    }

    /**
     * Show settings (grouped) or update them.
     *
     * Route:   GET|POST /panel/settings
     * Auth:    role:admin
     * Returns: JSON envelope
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
        return $this->ok($settings->grouped(), $ctx);
    }

    /**
     * SMS settings (read or update).
     *
     * Route:   GET|POST /panel/settings/sms
     * Auth:    role:admin
     * Returns: JSON envelope
     */
    public function sms(Request $request, MiddlewareContext $ctx): Response
    {
        $settings = $this->c->get('settings');
        if ($request->isMethod('POST')) {
            $settings->setMany($this->normalizeBooleans($this->input($request)));
            return $this->ok(null, $ctx);
        }
        $groups = $settings->grouped();
        return $this->ok(['sms' => $groups['sms'] ?? []], $ctx);
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
     * Payment settings (read or update).
     *
     * Route:   GET|POST /panel/settings/payment
     * Auth:    role:admin
     * Returns: JSON envelope
     */
    public function payment(Request $request, MiddlewareContext $ctx): Response
    {
        $settings = $this->c->get('settings');
        if ($request->isMethod('POST')) {
            $settings->setMany($this->normalizeBooleans($this->input($request)));
            return $this->ok(null, $ctx);
        }
        $groups = $settings->grouped();
        return $this->ok(['payments' => $groups['payments'] ?? []], $ctx);
    }

    /**
     * List the active cultures available in the panel.
     *
     * Route:   GET /panel/cultures
     * Auth:    any authenticated user
     * Returns: JSON envelope { data: { active, cultures: [{code,name,direction}] } }
     */
    public function cultures(Request $request, MiddlewareContext $ctx): Response
    {
        $culture = $this->c->get('culture');
        $rows = array_map(static function (array $row): array {
            return [
                'code' => (string) $row['code'],
                'name' => (string) ($row['name'] ?? $row['code']),
                'direction' => (string) $row['direction'],
            ];
        }, $culture->list());
        if ($rows === []) {
            $rows = [['code' => 'fa-IR', 'name' => 'فارسی', 'direction' => 'rtl']];
        }
        return $this->ok(['active' => $culture->code(), 'cultures' => $rows], $ctx);
    }

    /**
     * Persist the active culture (Admin only) and set the culture cookie.
     *
     * The choice is stored as app.default_culture (cached with the other
     * settings) and echoed to the client through a cookie so every later
     * request resolves it without a query parameter.
     *
     * Route:   POST /panel/culture
     * Auth:    role:admin
     * Body:    { culture: 'fa-IR'|'en-US' }
     * Returns: JSON envelope { data: { culture } }
     */
    public function setCulture(Request $request, MiddlewareContext $ctx): Response
    {
        $culture = $this->c->get('culture');
        $code = (string) ($this->input($request)['culture'] ?? '');
        try {
            $culture->setConfiguredDefault($code);
        } catch (\InvalidArgumentException) {
            return $this->fail('CULTURE_INVALID', 'این زبان در دسترس نیست', $ctx, 422, 'culture');
        }
        $this->c->get('log')->audit([
            'actor_id' => (int) $ctx->user['id'], 'actor_role' => 'admin', 'action' => 'settings.culture',
            'target_type' => 'settings', 'target_id' => 0, 'diff' => ['culture' => $code],
        ]);
        return $this->ok(['culture' => $culture->code()], $ctx)
            ->withCookie('culture', $culture->code(), time() + 31536000, '/', false, 'Lax');
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
