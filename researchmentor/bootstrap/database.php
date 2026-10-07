<?php
declare(strict_types=1);

/** Bootstrap for CLI tools that only need config + DB (no router). */
use App\Support\Env;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/Support/helpers.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');
