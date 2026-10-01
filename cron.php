<?php
declare(strict_types=1);

/**
 * File: cron.php
 *
 * Purpose: Command-line entry point for the panel's scheduled jobs. The app has
 *          no resident daemon, so recurring work (deadline alerts, automatic
 *          rider verification, nightly backups, expired-session cleanup) is
 *          triggered from the host's crontab:
 *
 *              (every five minutes) php /path/to/cron.php >> /path/to/logs/cron.log 2>&1
 *
 *          Every job is idempotent, so running it frequently is safe. Jobs
 *          whose settings switch is off are skipped. Use --force to run a job
 *          even when `scheduler.enabled` is false (useful for the first run
 *          right after enabling it), and --job=<name> to run a single job.
 *
 * @package Cli
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "cron.php may only be run from the command line.\n";
    exit(1);
}

define('BASE_PATH', __DIR__);

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/app/Support/Helpers.php';
require BASE_PATH . '/app/Bootstrap/App.php';
require BASE_PATH . '/app/Bootstrap/Database.php';
require BASE_PATH . '/app/Bootstrap/Bootstrap.php';

use App\Bootstrap\Bootstrap;

$options = getopt('', ['force', 'job::', 'quiet']);
$force = array_key_exists('force', $options);
$only = isset($options['job']) ? (string) $options['job'] : '';
$quiet = array_key_exists('quiet', $options);

$container = Bootstrap::container();
$settings = $container->get('settings');

/** Print a line unless --quiet was passed. */
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

if (!$force && (string) $settings->get('scheduler.enabled', '0') !== '1') {
    $say('[cron] scheduler.enabled is off — nothing to do. Pass --force to run anyway.');
    exit(0);
}

$say('[cron] started at ' . gmdate('c'));

try {
    /** @var \App\Services\Admin\SchedulerService $scheduler */
    $scheduler = $container->get('scheduler');
    $result = $only !== ''
        ? [$only => method_exists($scheduler, $only) ? $scheduler->{$only}() : ['ran' => false, 'count' => 0, 'note' => 'unknown_job']]
        : $scheduler->runAll();

    foreach ($result as $job => $outcome) {
        $note = isset($outcome['note']) ? ' (' . $outcome['note'] . ')' : '';
        $say(sprintf(
            '[cron] %-20s ran=%s count=%d%s',
            $job,
            !empty($outcome['ran']) ? 'yes' : 'no',
            (int) ($outcome['count'] ?? 0),
            $note
        ));
    }
    $say('[cron] finished at ' . gmdate('c'));
    exit(0);
} catch (\Throwable $e) {
    $say('[cron] failed: ' . $e->getMessage());
    exit(1);
}