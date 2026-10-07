#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/database.php';

use App\Support\Config;
use Database\Seeders\DemoSeeder;

if (Config::get('app.env') === 'production' && !in_array('--allow-production', $argv, true)) {
    fwrite(STDERR, "Refusing to load demo data in production. (Demo accounts share a known password.)\n");
    exit(1);
}
$seeder = new DemoSeeder();
try {
    $creds = $seeder->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . "\n");
    exit(1);
}
\App\Services\AI\PromptManager::sync();
echo "Demo data created. All demo accounts use the password in DEMO_PASSWORD (default: Demo-Passw0rd!2026)\n\n";
foreach ($creds as $c) {
    printf("  %-28s %-24s %s\n", $c['email'], $c['name'], $c['roles']);
}
echo "\nReminder: the AI provider is the mock unless ANTHROPIC_API_KEY is set; seeded AI reviews are labelled mock.\n";
