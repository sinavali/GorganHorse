<?php
declare(strict_types=1);

/**
 * File: app/Http/Kernel.php
 *
 * Purpose:
 *   Pipeline orchestrator. Builds the middleware context, runs the ordered
 *   middleware stack, matches the route, invokes the controller, applies
 *   response headers, and funnels all exceptions through the Handler
 *   (Technical §4.1, §5, §13, §28). All responses — success and error — are
 *   JSON envelopes; the backend does not render HTML (the UI is a separate
 *   client application consuming this API).
 *
 * Dependencies: Container bindings (settings, culture, auth, users, router,
 *               log, handler) resolved via the container.
 *
 * @package App\Http
 */

namespace App\Http;

use App\Bootstrap\Container;
use App\Bootstrap\Envelope;
use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Bootstrap\Router;
use App\Exceptions\Handler as ExceptionHandler;
use App\Exceptions\NotFoundException;
use App\Exceptions\ServerErrorException;
use App\Services\AuthService;
use App\Services\CultureService;
use App\Services\LogService;
use App\Services\SettingService;
use App\Services\UserService;
use Throwable;

/**
 * Class: Kernel
 *
 * Purpose: Run a request through the middleware pipeline and dispatch it.
 */
final class Kernel
{
    private Container $c;

    /**
     * @param Container $c Service container.
     */
    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    /**
     * Handle a request end to end and emit the response.
     *
     * @param Request $request Incoming request.
     * @return void
     */
    public function handle(Request $request): void
    {
        $response = $this->process($request);
        $response->send();
    }

    /**
     * Process a request and return the response (no emission).
     *
     * @param Request $request Incoming request.
     * @return Response
     */
    public function process(Request $request): Response
    {
        /** @var SettingService $settings */
        $settings = $this->c->get('settings');
        /** @var CultureService $culture */
        $culture = $this->c->get('culture');
        /** @var AuthService $auth */
        $auth = $this->c->get('auth');
        /** @var UserService $users */
        $users = $this->c->get('users');
        /** @var Router $router */
        $router = $this->c->get('router');
        /** @var LogService $log */
        $log = $this->c->get('log');
        /** @var ExceptionHandler $handler */
        $handler = $this->c->get('handler');

        $ctx = new MiddlewareContext($request, $settings, $culture, $auth, $users);

        try {
            // 1. Security headers (applied to the eventual response).
            Middleware::securityHeaders($ctx);

            // 2. Maintenance.
            if (($r = Middleware::maintenance($ctx)) !== null) { return $this->finalize($r, $ctx); }

            // 3. Culture.
            Middleware::culture($ctx);

            // Match the route first so we know auth requirements.
            $match = $router->match($request->method(), $request->path());
            if ($match === null) {
                throw new NotFoundException('Route not found', 'NOT_FOUND');
            }
            $route = $match['route'];
            foreach ($match['params'] as $k => $v) { $request->setAttr($k, $v); }

            $mwKeys = $route->middleware;
            $requiresAuth = !in_array('guest', $mwKeys, true);
            $isGuestRoute = in_array('guest', $mwKeys, true);

            // 4. Auth.
            if (($r = Middleware::auth($ctx, $requiresAuth)) !== null) { return $this->finalize($r, $ctx); }

            // 4b. Guest CSRF hydration. Guests have no session-bound token, so
            // expose the boot-time guest token via meta.csrf. The SPA echoes it
            // back through X-CSRF-Token on guest POSTs (install, login, signup,
            // OTP, captcha), where CsrfMiddleware validates against the same
            // instance value. Without this the client can never learn the
            // token: the guest cookie is HttpOnly and meta.csrf was empty.
            if ($isGuestRoute && $ctx->csrf === '') {
                $ctx->csrf = (string) $this->c->get('guest_csrf');
            }

            // 5. Disabled user.
            if (($r = Middleware::disabledUser($ctx)) !== null) { return $this->finalize($r, $ctx); }

            // 6. Role + rate limit.
            foreach ($mwKeys as $key) {
                if (str_starts_with($key, 'role:')) {
                    $roles = explode(',', substr($key, 5));
                    if (($r = Middleware::role($ctx, $roles)) !== null) { return $this->finalize($r, $ctx); }
                }
                if (str_starts_with($key, 'rate:')) {
                    if (($r = Middleware::rateLimit($ctx, substr($key, 5))) !== null) { return $this->finalize($r, $ctx); }
                }
            }

            // 7. Impersonation.
            if (($r = Middleware::impersonation($ctx)) !== null) { return $this->finalize($r, $ctx); }

            // 8. CSRF (runs for BOTH guest and authed state-changing routes).
            if (($r = Middleware::csrf($ctx, $isGuestRoute)) !== null) { return $this->finalize($r, $ctx); }

            $this->c->instance('request', $request);
            $this->c->instance('ctx', $ctx);

            // --- Dispatch the controller (fix: instantiate the class) ---
            $handlerDef = $route->handler;
            if (!is_array($handlerDef) || count($handlerDef) !== 2) {
                throw new ServerErrorException('SERVER_ERROR', 'Invalid route handler definition');
            }
            [$controllerClass, $controllerMethod] = $handlerDef;
            if (!class_exists($controllerClass)) {
                throw new ServerErrorException('SERVER_ERROR', 'Controller class missing: ' . (string) $controllerClass);
            }
            /** @var \App\Http\Controllers\BaseController $controller */
            $controller = new $controllerClass($this->c);
            $result = $controller->{$controllerMethod}($request, $ctx);

            if ($result instanceof Response) {
                $response = $result;
            } else {
                $response = Response::json(Envelope::ok($result, $this->envelopeMeta($ctx)));
            }
            $log->maybeCleanup((int) round(((float) $settings->get('logs.cleanup_probability', 0.01)) * 100));
            return $this->finalize($response, $ctx);
        } catch (Throwable $e) {
            try {
                $log->app('error', $e->getMessage(), [
                    'code' => $e instanceof \App\Exceptions\DomainException ? $e->errorCode : 'SERVER_ERROR',
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request_id' => $this->c->get('request_id'),
                ]);
            } catch (Throwable) {
                // Logging must never mask the original exception.
            }
            return $this->renderException($e, $ctx, $handler);
        }
    }

    /**
     * Build the standard envelope meta block.
     *
     * @param MiddlewareContext $ctx Context.
     * @return array
     */
    private function envelopeMeta(MiddlewareContext $ctx): array
    {
        return array_merge($ctx->culture->meta(), [
            'server_time_utc' => now_utc(),
            'impersonating' => $ctx->impersonatedBy !== null,
            'pending_verification' => $ctx->pendingVerification,
            'request_id' => $this->c->get('request_id'),
            'csrf' => $ctx->csrf,
        ]);
    }

    /**
     * Apply common headers to a response before emission.
     *
     * @param Response          $response Response.
     * @param MiddlewareContext $ctx      Context.
     * @return Response
     */
    private function finalize(Response $response, MiddlewareContext $ctx): Response
    {
        $response->withHeader('X-Request-Id', (string) $this->c->get('request_id'));
        return $response;
    }

    /**
     * Render an exception as a JSON error envelope.
     *
     * @param Throwable         $e       Exception.
     * @param MiddlewareContext $ctx     Context.
     * @param ExceptionHandler  $handler Exception handler.
     * @return Response
     */
    private function renderException(Throwable $e, MiddlewareContext $ctx, ExceptionHandler $handler): Response
    {
        $desc = $handler->describe($e);
        $message = $ctx->culture->translate($desc['code'], $desc['message']);
        $errors = [];
        foreach ($desc['errors'] as $err) {
            $errors[] = [
                'code' => $err['code'],
                'field' => $err['field'] ?? null,
                'message' => $ctx->culture->translate($err['code'], $err['message']),
            ];
        }
        $meta = array_merge($ctx->culture->meta(), ['request_id' => $this->c->get('request_id')]);

        if ($desc['status'] === 401) {
            return Response::json(Envelope::error($errors, $meta + ['csrf' => $ctx->csrf]), 401);
        }
        return Response::json(Envelope::error($errors, $meta + ['csrf' => $ctx->csrf]), $desc['status']);
    }

}