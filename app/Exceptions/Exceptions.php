<?php
declare(strict_types=1);

/**
 * File: app/Exceptions/Exceptions.php
 *
 * Purpose:
 *   All domain exceptions for the panel merged into one file (P23). Each
 *   exception carries a machine-readable error code, an HTTP status, a
 *   translation key, and an optional field name so the Handler can build
 *   the JSON envelope and HTML error page uniformly.
 *
 * Conventions:
 *   - Error codes are UPPER_SNAKE with a domain prefix (Blueprint §24.5).
 *   - Every exception funnels through App\Exceptions\Handler (P10).
 *
 * @package App\Exceptions
 */

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Class: DomainException
 *
 * Purpose:
 *   Base class for every domain exception. Carries an error code, HTTP status,
 *   optional field, and optional error code key for translation.
 */
class DomainException extends RuntimeException
{
    /**
     * @param string      $code       Machine-readable error code (e.g. AUTH_INVALID).
     * @param string      $message    Developer-facing English message.
     * @param int         $status     HTTP status code.
     * @param string|null $field      Related form field, if any.
     * @param array       $context    Extra context merged into the log entry.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?string $field = null,
        public readonly array $context = []
    ) {
        parent::__construct($message, 0);
    }
}

/**
 * Class: ValidationException
 *
 * Purpose: 422 field validation failure. May carry multiple field errors.
 */
final class ValidationException extends DomainException
{
    /** @var array<int,array{code:string,field:?string,message:string}> */
    private array $errors = [];

    /**
     * @param string $message Default message.
     * @param string $field   Single failing field.
     */
    public function __construct(string $message = 'Validation failed', ?string $field = null, string $code = 'VALIDATION_FAILED')
    {
        parent::__construct($code, $message, 422, $field);
        if ($field !== null) {
            $this->errors[] = ['code' => $code, 'field' => $field, 'message' => $message];
        }
    }

    /**
     * Add a field error.
     *
     * @param string      $field   Field name.
     * @param string      $message Message.
     * @param string|null $code    Machine code.
     * @return self
     */
    public function add(string $field, string $message, ?string $code = null): self
    {
        $this->errors[] = ['code' => $code ?? $this->errorCode, 'field' => $field, 'message' => $message];
        return $this;
    }

    /** @return array<int,array{code:string,field:?string,message:string}> */
    public function errors(): array { return $this->errors; }
}

/**
 * Class: AuthException
 *
 * Purpose: 401 authentication failure (missing/expired session, bad credentials).
 */
final class AuthException extends DomainException
{
    public function __construct(string $code = 'AUTH_SESSION_EXPIRED', string $message = 'Unauthenticated', int $status = 401)
    {
        parent::__construct($code, $message, $status);
    }
}

/**
 * Class: ForbiddenException
 *
 * Purpose: 403 role or ownership violation.
 */
final class ForbiddenException extends DomainException
{
    public function __construct(string $message = 'Forbidden', string $code = 'FORBIDDEN')
    {
        parent::__construct($code, $message, 403);
    }
}

/**
 * Class: NotFoundException
 *
 * Purpose: 404 when an entity cannot be located.
 */
final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'Not found', string $code = 'NOT_FOUND')
    {
        parent::__construct($code, $message, 404);
    }
}

/**
 * Class: ConflictException
 *
 * Purpose: 409 for duplicates and conflicting state.
 */
final class ConflictException extends DomainException
{
    public function __construct(string $code, string $message, ?string $field = null)
    {
        parent::__construct($code, $message, 409, $field);
    }
}

/**
 * Class: RateLimitException
 *
 * Purpose: 429 rate limiting with a Retry-After hint.
 */
final class RateLimitException extends DomainException
{
    /**
     * @param string $code        Error code.
     * @param int    $retryAfter  Seconds until the caller may retry.
     * @param string $message     Message.
     */
    public function __construct(string $code = 'RATE_LIMITED', int $retryAfter = 60, string $message = 'Too many requests')
    {
        parent::__construct($code, $message, 429, null, ['retry_after' => $retryAfter]);
    }

    /** @return int Seconds until retry allowed. */
    public function retryAfter(): int { return (int) ($this->context['retry_after'] ?? 60); }
}

/**
 * Class: DisabledUserException
 *
 * Purpose: 403 when a fully disabled user attempts to log in, or a limited user
 * attempts a write.
 */
final class DisabledUserException extends DomainException
{
    public function __construct(string $code = 'USER_DISABLED_LIMITED', string $message = 'Account restricted')
    {
        parent::__construct($code, $message, 403);
    }
}

/**
 * Class: MaintenanceException
 *
 * Purpose: 423 maintenance mode.
 */
final class MaintenanceException extends DomainException
{
    public function __construct(string $message = 'Under maintenance')
    {
        parent::__construct('MAINTENANCE', $message, 423);
    }
}

/**
 * Class: ServerErrorException
 *
 * Purpose: 500 or 503 for unrecoverable or unavailable dependencies.
 */
final class ServerErrorException extends DomainException
{
    public function __construct(string $code = 'SERVER_ERROR', string $message = 'Server error', int $status = 500)
    {
        parent::__construct($code, $message, $status);
    }
}

/**
 * Class: Handler
 *
 * Purpose:
 *   Central exception funnel. Renders JSON envelopes for API requests and HTML
 *   error pages for panel requests, and always logs the error with a request id.
 *
 * @package App\Exceptions
 */
final class Handler
{
    /**
     * @param bool                          $debug       Whether to expose traces.
     * @param callable|null                 $logger      function(string $level, string $message, array $ctx): void
     * @param callable|null                 $translator  function(string $code, string $fallback): string
     * @param callable|null                 $envelope    function(array $errors, array $meta): array
     */
    public function __construct(
        private bool $debug = false,
        private $logger = null,
        private $translator = null,
        private $envelope = null,
    ) {
    }

    /**
     * Convert any throwable into a normalised descriptor.
     *
     * @param Throwable $e Exception.
     * @return array{code:string,status:int,message:string,field:?string,errors:array,context:array}
     */
    public function describe(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return [
                'code' => $e->errorCode,
                'status' => $e->status,
                'message' => $e->getMessage(),
                'field' => $e->field,
                'errors' => $e->errors(),
                'context' => $e->context,
            ];
        }
        if ($e instanceof DomainException) {
            return [
                'code' => $e->errorCode,
                'status' => $e->status,
                'message' => $e->getMessage(),
                'field' => $e->field,
                'errors' => [['code' => $e->errorCode, 'field' => $e->field, 'message' => $e->getMessage()]],
                'context' => $e->context,
            ];
        }
        return [
            'code' => 'SERVER_ERROR',
            'status' => 500,
            'message' => $this->debug ? $e->getMessage() : 'Server error',
            'field' => null,
            'errors' => [['code' => 'SERVER_ERROR', 'field' => null, 'message' => $this->debug ? $e->getMessage() : 'Server error']],
            'context' => [],
        ];
    }

    /**
     * Translate a machine code into a human fa-IR message.
     *
     * @param string $code     Error code.
     * @param string $fallback Fallback message.
     * @return string
     */
    public function translate(string $code, string $fallback): string
    {
        if ($this->translator !== null) {
            $t = ($this->translator)($code, $fallback);
            if (is_string($t) && $t !== '') { return $t; }
        }
        return $fallback;
    }
}
