<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DashboardService;
use App\Support\{HttpException, Request, Response, Roles};

final class DashboardController extends BaseController
{
    private function need(Request $r, string ...$roles): array
    {
        $u = $this->user($r);
        if (!array_intersect($roles, $u['roles'])) {
            throw HttpException::forbidden();
        }
        return $u;
    }

    public function student(Request $r): Response
    {
        return $this->ok(DashboardService::student($this->need($r, Roles::STUDENT)));
    }

    public function supervisor(Request $r): Response
    {
        return $this->ok(DashboardService::supervisor($this->need($r, Roles::SUPERVISOR)));
    }

    public function administrator(Request $r): Response
    {
        return $this->ok(DashboardService::administrator($this->need($r, Roles::GRAD_ADMIN, Roles::COORDINATOR, Roles::SYSADMIN)));
    }

    /** Which dashboard(s) apply to the signed-in user. */
    public function home(Request $r): Response
    {
        $u = $this->user($r);
        $map = [Roles::STUDENT => 'student', Roles::SUPERVISOR => 'supervisor', Roles::GRAD_ADMIN => 'administrator', Roles::COORDINATOR => 'administrator', Roles::SYSADMIN => 'administrator', Roles::EXAMINER => 'examiner'];
        $kinds = array_values(array_unique(array_filter(array_map(fn($role) => $map[$role] ?? null, $u['roles']))));
        return $this->ok(['dashboards' => $kinds]);
    }

    public function health(Request $r): Response
    {
        $this->need($r, Roles::SYSADMIN);
        return $this->ok(DashboardService::health());
    }
}
