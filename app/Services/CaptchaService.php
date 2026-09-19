<?php
declare(strict_types=1);

/**
 * File: app/Services/CaptchaService.php
 *
 * Purpose:
 *   Server-generated image captcha backed by GD. Challenges are signed and
 *   stored with a 3-minute TTL and are single-use (Technical §11.5, Blueprint §23).
 *
 *   Works for two session kinds:
 *     - Authed sessions: the challenge is written into the session payload.
 *     - Guest sessions:  the challenge is written into a signed guest file
 *                        under cache/captcha-guest/ and verified against the
 *                        guest's `guest_csrf` cookie token.
 *
 * Dependencies: Database (session payload storage).
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Database;
use App\Bootstrap\Response;

/**
 * Class: CaptchaService
 *
 * Purpose: Issue, render, and verify single-use captcha challenges.
 */
final class CaptchaService
{
    private Database $db;

    /**
     * @param Database $db Main database.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Issue a captcha challenge bound to a session id.
     *
     * Persists:
     *   - the hashed answer + token + expiry into the session payload when a
     *     session row exists (for later verification),
     *   - a signed guest challenge file (keyed by guest token) for guest use, and
     *   - the plain code into a short-lived transient file for rendering only.
     *
     * @param string $sessionId Session id (guest or authed).
     * @param string $guestToken Guest CSRF token (used when no session row exists).
     * @return array{token:string,code:string}
     */
    public function issue(string $sessionId, string $guestToken = ''): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $token = random_token(12);
        $expires = time() + 180;
        $hash = password_hash($code, PASSWORD_DEFAULT);

        // Persist hash into the session row (if one exists).
        $this->savePayload($sessionId, [
            'captcha' => [
                'token' => $token,
                'hash' => $hash,
                'expires' => $expires,
            ],
        ]);

        // Guest fallback: also persist a signed record keyed by the guest token
        // (or session id when no token is supplied). Used to verify guests who
        // have no DB session row.
        if ($guestToken !== '' || $sessionId !== '') {
            $key = $guestToken !== '' ? $guestToken : $sessionId;
            $this->writeGuestChallenge($key, $token, $hash, $expires);
        }

        // Persist plain code transiently so /captcha/{token} can render it.
        $tmp = $this->tempPath($token);
        if (!is_dir(dirname($tmp))) { @mkdir(dirname($tmp), 0775, true); }
        @file_put_contents($tmp, json_encode(['code' => $code, 'expires' => $expires]));

        return ['token' => $token, 'code' => $code];
    }

    /**
     * Return the plain code for a token if still valid (render-only).
     *
     * @param string $token Captcha token.
     * @return string|null
     */
    public function peek(string $token): ?string
    {
        $path = $this->tempPath($token);
        if (!is_file($path)) { return null; }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['expires'] ?? 0) < time()) {
            @unlink($path);
            return null;
        }
        return (string) ($data['code'] ?? '');
    }

    /**
     * Render the captcha image for a code.
     *
     * @param string $code Plain code.
     * @return Response PNG response.
     */
    public function render(string $code): Response
    {
        if (!function_exists('imagecreatetruecolor')) {
            return (new Response($code, 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }
        $w = 160;
        $h = 56;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, 241, 245, 249);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);
        for ($i = 0; $i < 6; $i++) {
            $c = imagecolorallocate($img, random_int(180, 220), random_int(180, 220), random_int(180, 220));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        $len = strlen($code);
        for ($i = 0; $i < $len; $i++) {
            $c = imagecolorallocate($img, random_int(15, 90), random_int(80, 130), random_int(90, 140));
            imagestring($img, 5, 18 + $i * 24, random_int(12, 24), $code[$i], $c);
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        $bytes = (string) ob_get_clean();
        return new Response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }

    /**
     * Verify and consume a captcha answer.
     *
     * Checks the session payload first; falls back to the guest challenge file.
     *
     * @param string $sessionId  Session id.
     * @param string $token      Token.
     * @param string $answer     Answer.
     * @param string $guestToken Guest CSRF token (optional).
     * @return bool True on success; false on any mismatch/expiry.
     */
    public function verify(string $sessionId, string $token, string $answer, string $guestToken = ''): bool
    {
        // Always drop the transient render file.
        @unlink($this->tempPath($token));

        $answer = strtoupper(trim($answer));
        $ok = false;

        // 1. Session payload path.
        if ($sessionId !== '') {
            $row = $this->db->selectOne('SELECT payload FROM sessions WHERE id = :id', ['id' => $sessionId]);
            if ($row !== null) {
                $payload = json_decode((string) ($row['payload'] ?? '{}'), true) ?: [];
                $captcha = $payload['captcha'] ?? null;
                if (is_array($captcha)
                    && ($captcha['token'] ?? '') === $token
                    && ($captcha['expires'] ?? 0) >= time()
                    && password_verify($answer, (string) $captcha['hash'])) {
                    $ok = true;
                }
                if (is_array($captcha) && ($captcha['token'] ?? '') === $token) {
                    unset($payload['captcha']);
                    $this->db->update('sessions', ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => $sessionId]);
                }
            }
        }

        // 2. Guest challenge file path.
        if (!$ok) {
            $key = $guestToken !== '' ? $guestToken : $sessionId;
            if ($key !== '') {
                $ok = $this->consumeGuestChallenge($key, $token, $answer);
            }
        }
        return $ok;
    }

    /**
     * Persist a payload merge into a session row (no-op when the row is absent).
     *
     * @param string $sessionId Session id.
     * @param array  $merge     Payload keys.
     * @return void
     */
    private function savePayload(string $sessionId, array $merge): void
    {
        if ($sessionId === '') { return; }
        $row = $this->db->selectOne('SELECT payload FROM sessions WHERE id = :id', ['id' => $sessionId]);
        if ($row === null) { return; }
        $payload = json_decode((string) $row['payload'], true) ?: [];
        $this->db->update('sessions', ['payload' => json_encode(array_merge($payload, $merge), JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => $sessionId]);
    }

    /**
     * Write (or replace) the guest challenge record for a guest token.
     *
     * @param string $guestKey Guest token or session id.
     * @param string $token    Captcha token.
     * @param string $hash     Password hash of the code.
     * @param int    $expires  Unix expiry.
     * @return void
     */
    private function writeGuestChallenge(string $guestKey, string $token, string $hash, int $expires): void
    {
        $path = $this->guestPath($guestKey);
        if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0775, true); }
        @file_put_contents($path, json_encode([
            'token' => $token,
            'hash' => $hash,
            'expires' => $expires,
            'bound' => hash_hmac('sha256', $guestKey, (string) $this->appKey()),
        ]));
    }

    /**
     * Consume a guest challenge, verifying the bound HMAC.
     *
     * @param string $guestKey Guest token or session id.
     * @param string $token    Captcha token.
     * @param string $answer   Uppercased answer.
     * @return bool
     */
    private function consumeGuestChallenge(string $guestKey, string $token, string $answer): bool
    {
        $path = $this->guestPath($guestKey);
        if (!is_file($path)) { return false; }
        $data = json_decode((string) file_get_contents($path), true);
        @unlink($path);
        if (!is_array($data)
            || ($data['token'] ?? '') !== $token
            || ($data['expires'] ?? 0) < time()) {
            return false;
        }
        $bound = (string) ($data['bound'] ?? '');
        $expected = hash_hmac('sha256', $guestKey, (string) $this->appKey());
        if ($bound === '' || !hash_equals($expected, $bound)) {
            return false;
        }
        return password_verify($answer, (string) $data['hash']);
    }

    /**
     * Read the application key for HMAC binding.
     *
     * @return string
     */
    private function appKey(): string
    {
        try {
            $settings = \App\Support\container('settings');
            if ($settings instanceof \App\Services\SettingService) {
                return (string) $settings->get('app.key', 'dev');
            }
        } catch (\Throwable) {
        }
        return 'dev';
    }

    /**
     * Transient storage path for a captcha token (image rendering).
     *
     * @param string $token Token.
     * @return string
     */
    private function tempPath(string $token): string
    {
        $safe = preg_replace('/[^a-z0-9]/i', '', $token) ?: 'x';
        return BASE_PATH . '/cache/captcha/' . $safe . '.json';
    }

    /**
     * Guest challenge file path.
     *
     * @param string $guestKey Guest token or session id.
     * @return string
     */
    private function guestPath(string $guestKey): string
    {
        $safe = preg_replace('/[^a-z0-9]/i', '', $guestKey) ?: 'x';
        return BASE_PATH . '/cache/captcha-guest/' . $safe . '.json';
    }
}