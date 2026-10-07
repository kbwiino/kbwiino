<?php
declare(strict_types=1);

use App\Support\Config;
use App\Support\Env;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/Support/helpers.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC'); // all timestamps are stored in UTC

if (Config::get('app.env') === 'production' || !Config::get('app.debug')) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', Config::basePath('storage/logs/php-error.log'));
mb_internal_encoding('UTF-8');

return new \App\Support\Application();
