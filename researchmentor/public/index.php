<?php
declare(strict_types=1);

/** Front controller. All requests are routed here by Apache (see public/.htaccess). */

$app = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Request;
use App\Support\Session;

$request = Request::capture();
$request->session = Session::native();
$app->handle($request)->send();
