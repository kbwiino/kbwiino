#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Background worker. Run under systemd/supervisor (see deploy/researchmentor-worker.service) or via cron:
 *   php bin/worker.php            # loop forever
 *   php bin/worker.php --once     # drain the queue then exit (cron-friendly)
 *   php bin/worker.php --purge    # run retention purge + housekeeping then exit
 */
require __DIR__ . '/../bootstrap/database.php';

use App\Services\QueueDispatcher;
use App\Support\Logger;

if (in_array('--purge', $argv, true)) {
    QueueDispatcher::run('purge_expired', []);
    echo "Retention purge complete.\n";
    exit(0);
}
$once = in_array('--once', $argv, true);
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, function () use (&$running) { $running = false; });
    }
}
$idleSince = time();
while ($running) {
    try {
        $did = QueueDispatcher::work();
    } catch (Throwable $e) {
        Logger::error('Worker loop error: ' . $e->getMessage());
        \App\Support\Database::disconnect();
        sleep(5);
        continue;
    }
    if ($did) {
        $idleSince = time();
        continue;
    }
    if ($once) {
        break;
    }
    sleep(2);
    // recycle the DB connection when idle to survive MySQL wait_timeout
    if (time() - $idleSince > 300) {
        \App\Support\Database::disconnect();
        $idleSince = time();
    }
}
echo "Worker stopped.\n";
