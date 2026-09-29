<?php
declare(strict_types=1);

/**
 * File: tests/Unit/ModelsAndExceptionsTest.php
 * Purpose: Unit tests for Exceptions, Models, Container, and Core Bootstrapping.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/app/Support/Helpers.php';
require_once BASE_PATH . '/app/Bootstrap/App.php';

use App\Bootstrap\Container;
use App\Exceptions\AuthException;
use App\Exceptions\ConflictException;
use App\Exceptions\DisabledUserException;
use App\Exceptions\DomainException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\Handler;
use App\Exceptions\MaintenanceException;
use App\Exceptions\NotFoundException;
use App\Exceptions\RateLimitException;
use App\Exceptions\ServerErrorException;
use App\Exceptions\ValidationException;
use App\Models\BaseModel;

final class ModelsAndExceptionsTest
{
    public static function run(): void
    {
        echo "Running Models, Exceptions & Bootstrapping Unit Tests...\n";

        // 1. Domain Exceptions
        $de = new DomainException('SIGNUP_DUPLICATE', 'Duplicate signup', 409, 'field1');
        assert($de->errorCode === 'SIGNUP_DUPLICATE');
        assert($de->status === 409);
        assert($de->field === 'field1');

        $ve = new ValidationException('Validation failed', 'phone', 'PHONE_INVALID');
        $ve->add('email', 'Email invalid', 'EMAIL_INVALID');
        assert(count($ve->errors()) === 2);

        $ae = new AuthException('AUTH_SESSION_EXPIRED', 'Expired', 401);
        assert($ae->status === 401);

        $fe = new ForbiddenException('Forbidden', 'FORBIDDEN');
        assert($fe->status === 403);

        $nfe = new NotFoundException('Not found', 'NOT_FOUND');
        assert($nfe->status === 404);

        $ce = new ConflictException('CONFLICT', 'Conflict state');
        assert($ce->status === 409);

        $rle = new RateLimitException('RATE_LIMITED', 30);
        assert($rle->status === 429);
        assert($rle->retryAfter() === 30);

        $due = new DisabledUserException('USER_DISABLED_FULL', 'Account disabled');
        assert($due->status === 403);

        $me = new MaintenanceException('Maintenance mode');
        assert($me->status === 423);

        $se = new ServerErrorException('SERVER_ERROR', 'Error', 500);
        assert($se->status === 500);

        // 2. Exception Handler
        $handler = new Handler(true, null, fn($code, $fb) => $fb, null);
        $desc = $handler->describe($de);
        assert($desc['code'] === 'SIGNUP_DUPLICATE');
        assert($desc['status'] === 409);

        // 3. BaseModel
        $model = new class(['id' => 10, 'name' => 'Test', 'is_demo' => 1]) extends BaseModel {};
        assert($model->get('id') === 10);
        assert($model->get('nonexistent', 'default') === 'default');
        assert($model->toArray() === ['id' => 10, 'name' => 'Test', 'is_demo' => 1]);

        // 4. Container
        $c = new Container();
        $c->instance('str', 'hello');
        assert($c->get('str') === 'hello');
        $c->singleton('counter', fn() => rand(1, 1000000));
        $val1 = $c->get('counter');
        $val2 = $c->get('counter');
        assert($val1 === $val2);

        echo "  Models, Exceptions & Bootstrapping Unit Tests Passed!\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    ModelsAndExceptionsTest::run();
}
