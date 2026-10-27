<?php
declare(strict_types=1);

namespace App\Support;

/** Session wrapper. In production it binds to $_SESSION; tests use a plain array. */
final class Session
{
    private array $data;
    private bool $native;
    public bool $regenerated = false;
    public bool $destroyed = false;

    private function __construct(array &$data, bool $native)
    {
        $this->data = &$data;
        $this->native = $native;
    }

    public static function native(): self
    {
        $cfg = Config::get('security');
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name($cfg['session_name']);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => (bool) $cfg['secure_cookie'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        return new self($_SESSION, true);
    }

    public static function array(array $initial = []): self
    {
        $data = $initial;
        return new self($data, false);
    }

    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function has(string $key): bool { return isset($this->data[$key]); }
    public function forget(string $key): void { unset($this->data[$key]); }
    public function all(): array { return $this->data; }

    public function regenerate(): void
    {
        $this->regenerated = true;
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        // rotate CSRF token with the session id
        $this->data['_csrf'] = bin2hex(random_bytes(32));
    }

    public function destroy(): void
    {
        $this->destroyed = true;
        $this->data = [];
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            setcookie(session_name(), '', time() - 3600, '/');
        }
    }

    public function csrfToken(): string
    {
        if (empty($this->data['_csrf'])) {
            $this->data['_csrf'] = bin2hex(random_bytes(32));
        }
        return $this->data['_csrf'];
    }

    public function flash(string $key, mixed $value): void { $this->data['_flash'][$key] = $value; }
    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $v = $this->data['_flash'][$key] ?? $default;
        unset($this->data['_flash'][$key]);
        return $v;
    }
}
