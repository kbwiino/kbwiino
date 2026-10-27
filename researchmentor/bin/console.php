#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/database.php';

use App\Services\AI\PromptManager;
use App\Support\{Config, Database};

$cmd = $argv[1] ?? 'help';
switch ($cmd) {
    case 'prompts:sync':
        echo 'Imported ' . PromptManager::sync() . " prompt template(s).\n";
        break;
    case 'user:create':
        // php bin/console.php user:create email "First" "Last" role [institution_code]
        [, , $email, $first, $last, $role] = array_pad($argv, 6, null);
        $code = $argv[6] ?? null;
        if (!$email || !$first || !$last || !$role) { fwrite(STDERR, "Usage: user:create email first last role [institution_code]\n"); exit(1); }
        $inst = $code ? \App\Models\Institution::first(['code' => $code]) : (\App\Models\Institution::first([]) ?? null);
        if (!$inst) { fwrite(STDERR, "Institution not found. Create one first (see docs/DEPLOYMENT.md, 'First administrator').\n"); exit(1); }
        $pw = $_ENV['NEW_USER_PASSWORD'] ?? getenv('NEW_USER_PASSWORD');
        if (!$pw) { fwrite(STDERR, "Set NEW_USER_PASSWORD in the environment (not on the command line).\n"); exit(1); }
        try {
            App\Services\AuthService::validatePasswordStrength($pw);
        } catch (App\Support\HttpException $e) {
            fwrite(STDERR, 'Password rejected: ' . implode(' ', $e->details['password'] ?? [$e->getMessage()]) . "\n");
            exit(1);
        }
        $id = \App\Models\User::create(['institution_id' => $inst['id'], 'email' => strtolower($email), 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'first_name' => $first, 'last_name' => $last]);
        Database::execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, \App\Models\Role::first(['slug' => $role])['id'] ?? throw new RuntimeException('Unknown role')]);
        echo "Created user {$email} ({$role}).\n";
        break;
    case 'institution:create':
        // php bin/console.php institution:create "Name" CODE [country]
        [, , $name, $code] = array_pad($argv, 4, null);
        if (!$name || !$code) { fwrite(STDERR, "Usage: institution:create \"Name\" CODE [country]\n"); exit(1); }
        $id = \App\Models\Institution::create(['name' => $name, 'code' => strtoupper($code), 'country' => $argv[4] ?? 'UG']);
        $fid = \App\Models\Faculty::create(['institution_id' => $id, 'name' => 'General Faculty', 'code' => 'GEN']);
        $did = \App\Models\Department::create(['institution_id' => $id, 'faculty_id' => $fid, 'name' => 'General Department', 'code' => 'GEN']);
        \App\Models\Programme::create(['institution_id' => $id, 'department_id' => $did, 'name' => 'Master\'s programme', 'code' => 'MAS', 'degree_level' => 'masters']);
        \App\Models\Programme::create(['institution_id' => $id, 'department_id' => $did, 'name' => 'PhD programme', 'code' => 'PHD', 'degree_level' => 'phd']);
        $y = (int) gmdate('Y');
        \App\Models\AcademicYear::create(['institution_id' => $id, 'label' => $y . '/' . ($y + 1), 'starts_on' => $y . '-08-01', 'ends_on' => ($y + 1) . '-07-31', 'is_current' => 1]);
        echo "Created institution {$name} ({$code}) with a starter structure; edit faculties/departments/programmes in the database or admin UI.\n";
        break;
    case 'retention:purge':
        \App\Services\QueueDispatcher::run('purge_expired', []);
        echo "Purged.\n";
        break;
    case 'check':
        $h = \App\Services\DashboardService::health();
        foreach ($h as $k => $v) {
            if ($k === 'overall_ok') continue;
            printf("%-16s %s %s\n", $k, ($v['ok'] ?? false) ? 'OK  ' : 'FAIL', json_encode(array_diff_key($v, ['ok' => 1])));
        }
        exit($h['overall_ok'] ? 0 : 1);
    default:
        echo "Commands: prompts:sync | institution:create | user:create | retention:purge | check\n";
}
