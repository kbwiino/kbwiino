<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Config;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;

final class AuthService
{
    /** Resolve the authenticated user for this request (or null). Enforces idle timeout. */
    public static function currentUser(Request $request): ?array
    {
        if (isset($request->attributes['user'])) {
            return $request->attributes['user'];
        }
        $session = $request->session;
        $uid = $session?->get('user_id');
        if (!$uid) {
            return null;
        }
        $last = (int) $session->get('last_activity', 0);
        if ($last > 0 && time() - $last > Config::get('security.session_lifetime') * 60) {
            $session->destroy();
            return null;
        }
        $user = self::loadUser((int) $uid);
        if ($user === null || $user['status'] !== 'active') {
            $session->destroy();
            return null;
        }
        $session->set('last_activity', time());
        return $user;
    }

    public static function loadUser(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        return $row ? self::hydrate($row) : null;
    }

    private static function hydrate(array $row): array
    {
        $roles = Database::fetchAll(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$row['id']]
        );
        return [
            'id' => (int) $row['id'],
            'uuid' => $row['uuid'],
            'institution_id' => (int) $row['institution_id'],
            'department_id' => $row['department_id'] !== null ? (int) $row['department_id'] : null,
            'programme_id' => $row['programme_id'] !== null ? (int) $row['programme_id'] : null,
            'email' => $row['email'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'status' => $row['status'],
            'roles' => array_column($roles, 'slug'),
        ];
    }

    public static function attempt(Request $request, string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $max = (int) Config::get('security.login_max_attempts');
        // Throttle by IP regardless of whether the account exists.
        RateLimiter::enforce('login-ip:' . $request->ip(), $max * 4, 900, 'Too many login attempts. Please try again later.');

        $row = Database::fetchOne('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        // Always run a hash verification so response time doesn't reveal whether the account exists.
        $hash = $row['password_hash'] ?? '$2y$10$wjD9POYgN2JhVKcKF4PCkeBsgNVWaXTy9VmW2X9dX.HS/OCisu8lm';
        $ok = password_verify($password, $hash);

        if (!$row || $row['status'] !== 'active') {
            AuditLogger::log($request, 'auth.login_failed', 'user', null, ['email_hash' => hash('sha256', $email)]);
            throw new HttpException(401, 'invalid_credentials', 'Invalid email or password.');
        }
        if ($row['locked_until'] && strtotime($row['locked_until'] . ' UTC') > time()) {
            AuditLogger::log($request, 'auth.login_locked', 'user', $row['uuid'], [], self::hydrate($row));
            throw new HttpException(423, 'account_locked', 'This account is temporarily locked after repeated failed sign-ins. Try again later.');
        }
        if (!$ok) {
            $fails = (int) $row['failed_logins'] + 1;
            $upd = ['failed_logins' => $fails];
            if ($fails >= $max) {
                $upd['locked_until'] = gmdate('Y-m-d H:i:s', time() + Config::get('security.login_lockout_minutes') * 60);
                $upd['failed_logins'] = 0;
            }
            User::updateById((int) $row['id'], $upd);
            AuditLogger::log($request, 'auth.login_failed', 'user', $row['uuid'], [], self::hydrate($row));
            throw new HttpException(401, 'invalid_credentials', 'Invalid email or password.');
        }

        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            User::updateById((int) $row['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        User::updateById((int) $row['id'], ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => gmdate('Y-m-d H:i:s')]);

        $request->session->regenerate();           // session fixation defence
        $request->session->set('user_id', (int) $row['id']);
        $request->session->set('last_activity', time());
        $user = self::hydrate($row);
        $request->attributes['user'] = $user;
        AuditLogger::log($request, 'auth.login', 'user', $row['uuid'], [], $user);
        return $user;
    }

    public static function logout(Request $request): void
    {
        if ($request->user()) {
            AuditLogger::log($request, 'auth.logout', 'user', $request->user()['uuid']);
        }
        $request->session?->destroy();
    }

    public static function validatePasswordStrength(string $password): void
    {
        $min = (int) Config::get('security.password_min_length');
        $errors = [];
        if (mb_strlen($password) < $min) {
            $errors[] = "Must be at least {$min} characters.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Must contain letters and numbers.';
        }
        if ($errors) {
            throw HttpException::unprocessable('Weak password.', ['password' => $errors]);
        }
    }

    public static function hasRole(array $user, string ...$roles): bool
    {
        return (bool) array_intersect($roles, $user['roles']);
    }
}
