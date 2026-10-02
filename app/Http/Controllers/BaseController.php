<?php
declare(strict_types=1);

/**
 * File: app/Http/Controllers/BaseController.php
 *
 * Purpose:
 *   Shared behaviour for every controller: service container access, envelope
 *   building, request input helpers, pagination parsing, and a uniform JSON
 *   response factory (Technical §10). The backend exposes a pure JSON API; no
 *   server-side view rendering is performed (the UI lives in a separate
 *   repository and consumes this API).
 *
 * @package App\Http\Controllers
 */

namespace App\Http\Controllers;

use App\Bootstrap\Container;
use App\Bootstrap\Envelope;
use App\Bootstrap\Request;
use App\Bootstrap\Response;
use App\Http\MiddlewareContext;

/**
 * Class: BaseController
 *
 * Purpose: Provide helpers used by all controllers.
 */
abstract class BaseController
{
    /** @var Container Service container. */
    protected Container $c;

    /**
     * @param Container $c Service container.
     */
    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    /**
     * Build a success JSON response using the standard envelope.
     *
     * @param mixed             $data    Response data.
     * @param MiddlewareContext $ctx     Context.
     * @param int               $status  HTTP status.
     * @param array             $extra   Extra meta.
     * @return Response
     */
    protected function ok(mixed $data, MiddlewareContext $ctx, int $status = 200, array $extra = []): Response
    {
        return Response::json(Envelope::ok($data, $this->meta($ctx, $extra)), $status);
    }

    /**
     * Build an error JSON response using the standard envelope.
     *
     * @param string            $code    Error code.
     * @param string            $message Message.
     * @param MiddlewareContext $ctx     Context.
     * @param int               $status  HTTP status.
     * @param string|null       $field   Field name.
     * @return Response
     */
    protected function fail(string $code, string $message, MiddlewareContext $ctx, int $status = 400, ?string $field = null): Response
    {
        return Response::json(Envelope::error(
            [['code' => $code, 'field' => $field, 'message' => $message]],
            $this->meta($ctx)
        ), $status);
    }

    /**
     * Build the meta block.
     *
     * @param MiddlewareContext $ctx   Context.
     * @param array             $extra Extra meta.
     * @return array
     */
    protected function meta(MiddlewareContext $ctx, array $extra = []): array
    {
        $page = (int) $ctx->request->query('page', 1);
        return array_merge($ctx->culture->meta(), [
            'server_time_utc' => now_utc(),
            'impersonating' => $ctx->impersonatedBy !== null,
            'pending_verification' => $ctx->pendingVerification,
            'request_id' => $this->c->get('request_id'),
            'page' => max(1, $page),
            'csrf' => $ctx->csrf,
        ], $extra);
    }

    /**
     * Read the current page from the request.
     *
     * @param Request $request Request.
     * @return int
     */
    protected function page(Request $request): int
    {
        return max(1, (int) $request->query('page', 1));
    }

    /**
     * Read the page size from the request (bounded).
     *
     * @param Request $request Request.
     * @return int
     */
    protected function perPage(Request $request): int
    {
        $per = (int) $request->query('per_page', (int) $this->c->get('settings')->get('reports.default_per_page', 50));
        return max(1, min(250, $per));
    }

    /**
     * Collect input from JSON body or form body.
     *
     * @param Request $request Request.
     * @return array
     */
    protected function input(Request $request): array
    {
        $json = $request->json();
        if ($json !== []) { return $json; }
        return $request->post();
    }

    /**
     * Download a generated temporary file and delete it after the response.
     *
     * CSV exports are written to cache/ because Response streams downloads
     * from disk; without the shutdown cleanup those files accumulate forever.
     *
     * @param string $path Absolute path of the generated file.
     * @param string $name Download filename.
     * @return Response
     */
    protected function tempDownload(string $path, string $name): Response
    {
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) { @unlink($path); }
        });
        return Response::download($path, $name);
    }

}
