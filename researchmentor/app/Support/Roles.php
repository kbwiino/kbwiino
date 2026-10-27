<?php
declare(strict_types=1);

namespace App\Support;

final class Roles
{
    public const STUDENT = 'student';
    public const SUPERVISOR = 'supervisor';
    public const COORDINATOR = 'coordinator';
    public const GRAD_ADMIN = 'grad_admin';
    public const EXAMINER = 'external_examiner';
    public const SYSADMIN = 'sysadmin';

    public const LABELS = [
        self::STUDENT => 'Student',
        self::SUPERVISOR => 'Supervisor',
        self::COORDINATOR => 'Research Coordinator',
        self::GRAD_ADMIN => 'Graduate School Administrator',
        self::EXAMINER => 'External Examiner',
        self::SYSADMIN => 'System Administrator',
    ];

    public static function all(): array { return array_keys(self::LABELS); }
}
