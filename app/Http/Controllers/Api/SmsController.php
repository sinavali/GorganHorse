<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/Api/SmsController.php
 *
 * Purpose:
 *   API endpoint for sending SMS with template variables (Technical §20).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: SmsController
 * Purpose: Send SMS messages via API with template variable substitution.
 */
final class SmsController extends BaseController
{
    /**
     * Send an SMS (with optional template variable substitution).
     *
     * Route:   POST /api/sms/send
     * Auth:    role:admin,manager
     * Returns: JSON envelope { data: { sent, recipient, status } }
     */
    public function send(Request $request, MiddlewareContext $ctx): Response
    {
        $input = $this->input($request);
        $phone = (string) ($input['phone'] ?? '');
        $templateId = (int) ($input['template_id'] ?? 0);
        $message = (string) ($input['message'] ?? '');

        if ($phone === '') {
            return $this->fail('VALIDATION_FAILED', 'شماره پیامک الزامی است', $ctx, 422);
        }

        $sms = $this->c->get('sms');

        $body = $message;
        if ($templateId > 0) {
            $tpl = $this->c->get('db')->selectOne('SELECT body, variables FROM sms_templates WHERE id = :id AND is_active = 1', ['id' => $templateId]);
            if ($tpl !== null) {
                $body = (string) ($tpl['body'] ?? '');
                $variables = json_decode((string) ($tpl['variables'] ?? '[]'), true) ?? [];
                if (is_array($variables)) {
                    foreach ($variables as $var) {
                        $key = '{' . $var . '}';
                        $val = (string) ($input[$var] ?? '');
                        $body = str_replace($key, $val, $body);
                    }
                }
            }
        }

        if ($body === '') {
            return $this->fail('VALIDATION_FAILED', 'پیام یا قالب الزامی است', $ctx, 422);
        }

        $sent = $sms->send($phone, $body);
        return $this->ok([
            'sent' => $sent,
            'recipient' => $phone,
            'status' => $sent ? 'sent' : 'failed',
        ], $ctx);
    }
}