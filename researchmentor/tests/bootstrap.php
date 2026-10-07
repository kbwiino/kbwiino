<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/Support/helpers.php';

\App\Support\Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');

// Isolated private storage for tests.
$tmp = sys_get_temp_dir() . '/rm_test_storage_' . getmypid();
@mkdir($tmp . '/private', 0775, true);
@mkdir($tmp . '/exports', 0775, true);
putenv('PRIVATE_STORAGE_PATH=' . $tmp . '/private');
$_ENV['PRIVATE_STORAGE_PATH'] = $tmp . '/private';
putenv('EXPORT_STORAGE_PATH=' . $tmp . '/exports');
$_ENV['EXPORT_STORAGE_PATH'] = $tmp . '/exports';

if (\App\Support\Config::get('database.database') !== 'researchmentor_test') {
    fwrite(STDERR, "Refusing to run tests against non-test database.\n");
    exit(1);
}
$migrator = new \App\Support\Migrator();
$migrator->fresh();
$migrator->migrate();

register_shutdown_function(function () use ($tmp) {
    // best-effort cleanup of temp storage
    if (is_dir($tmp)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($tmp);
    }
});
