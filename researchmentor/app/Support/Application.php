<?php
declare(strict_types=1);

namespace App\Support;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SecurityHeaders;
use PDOException;

final class Application
{
    private Router $router;

    public function __construct(?Router $router = null)
    {
        if ($router === null) {
            $router = new Router();
            (require Config::basePath('routes/web.php'))($router);
            (require Config::basePath('routes/api.php'))($router);
        }
        $this->router = $router;
    }

    public function router(): Router { return $this->router; }

    public function handle(Request $request): Response
    {
        $request->session ??= Session::array();
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $response = $this->renderException($e, $request);
        }
        return (new SecurityHeaders())->decorate($response, $request);
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method === 'HEAD' ? 'GET' : $request->method, $request->path);
        if ($match === null) {
            throw HttpException::notFound('Page');
        }
        $request->params = $match['params'];
        [$class, $method] = $match['handler'];

        $core = function (Request $r) use ($class, $method): Response {
            $controller = new $class();
            $result = $controller->$method($r);
            if (!$result instanceof Response) {
                throw new \LogicException("Controller {$class}::{$method} must return a Response.");
            }
            return $result;
        };

        $pipeline = $core;
        foreach (array_reverse($match['middleware']) as $spec) {
            $mw = $this->makeMiddleware($spec);
            $next = $pipeline;
            $pipeline = fn(Request $r): Response => $mw->handle($r, $next);
        }
        return $pipeline($request);
    }

    private function makeMiddleware(string $spec): object
    {
        [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);
        return match ($name) {
            'auth' => new AuthMiddleware(),
            'csrf' => new CsrfMiddleware(),
            'role' => new RoleMiddleware(explode(',', (string) $arg)),
            'throttle' => new \App\Middleware\ThrottleMiddleware((int) ($arg ?: 300)),
            default => throw new \InvalidArgumentException("Unknown middleware {$name}"),
        };
    }

    private function renderException(\Throwable $e, Request $request): Response
    {
        if ($e instanceof HttpException) {
            if ($request->wantsJson()) {
                return Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details);
            }
            if ($e->status === 401) {
                return Response::redirect('/login');
            }
            return Response::html(View::render('errors/error', ['status' => $e->status, 'message' => $e->getMessage(), 'title' => 'Error ' . $e->status], 'layouts/plain'), $e->status);
        }

        $ref = bin2hex(random_bytes(4));
        Logger::error('Unhandled exception [' . $ref . ']: ' . get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'path' => $request->path,
            'trace' => Config::get('app.debug') ? $e->getTraceAsString() : null,
        ]);
        $debug = (bool) Config::get('app.debug');
        $message = $debug ? $e->getMessage() : 'An unexpected error occurred. Reference: ' . $ref;
        if ($e instanceof PDOException && !$debug) {
            $message = 'A database error occurred. Reference: ' . $ref;
        }
        if ($request->wantsJson()) {
            return Response::error(500, 'server_error', $message);
        }
        return Response::html(View::render('errors/error', ['status' => 500, 'message' => $message, 'title' => 'Server error'], 'layouts/plain'), 500);
    }
}
