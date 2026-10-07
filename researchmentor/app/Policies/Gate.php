<?php
declare(strict_types=1);

namespace App\Policies;

use App\Support\HttpException;

/** Central entry point: Gate::authorize($user, 'project.update', $project). */
final class Gate
{
    private const MAP = [
        'project' => ProjectPolicy::class,
        'document' => DocumentPolicy::class,
        'review' => ReviewPolicy::class,
        'supervision' => SupervisionPolicy::class,
        'knowledge' => KnowledgePolicy::class,
        'viva' => VivaPolicy::class,
    ];

    public static function allows(array $user, string $ability, mixed ...$args): bool
    {
        [$resource, $method] = explode('.', $ability, 2);
        $class = self::MAP[$resource] ?? throw new \InvalidArgumentException("No policy for {$resource}");
        $policy = new $class();
        if (!method_exists($policy, $method)) {
            throw new \InvalidArgumentException("Policy {$class} has no ability {$method}");
        }
        return (bool) $policy->$method($user, ...$args);
    }

    public static function authorize(array $user, string $ability, mixed ...$args): void
    {
        if (!self::allows($user, $ability, ...$args)) {
            throw HttpException::forbidden();
        }
    }
}
