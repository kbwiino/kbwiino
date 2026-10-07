<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;

final class NotificationService
{
    public static function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 255),
            'body' => $body,
            'link' => $link,
        ]);
    }

    /** @param int[] $userIds */
    public static function notifyMany(array $userIds, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        foreach (array_unique($userIds) as $id) {
            self::notify((int) $id, $type, $title, $body, $link);
        }
    }
}
