#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/database.php';

use App\Support\Migrator;

$cmd = $argv[1] ?? 'up';
$m = new Migrator();
switch ($cmd) {
    case 'up':
        $ran = $m->migrate();
        echo $ran ? "Applied:\n  " . implode("\n  ", $ran) . "\n" : "Nothing to migrate.\n";
        break;
    case 'status':
        foreach ($m->status() as $name => $applied) {
            printf("[%s] %s\n", $applied ? 'x' : ' ', $name);
        }
        break;
    case 'fresh':
        $m->fresh();
        echo "All tables dropped.\n";
        $ran = $m->migrate();
        echo "Applied:\n  " . implode("\n  ", $ran) . "\n";
        break;
    default:
        fwrite(STDERR, "Usage: php bin/migrate.php [up|status|fresh]\n");
        exit(1);
}
