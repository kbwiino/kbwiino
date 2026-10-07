<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Notification;
use App\Support\{Database, HttpException, Request, Response};

final class NotificationController extends BaseController
{
    public function index(Request $r): Response
    {
        $u = $this->user($r);
        $unreadOnly = (bool) $r->input('unread');
        $per = $this->perPage($r, 30);
        $rows = Database::fetchAll('SELECT * FROM notifications WHERE user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '') . ' ORDER BY id DESC LIMIT ' . $per, [$u['id']]);
        $unread = (int) Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']]);
        return $this->ok([
            'unread_count' => $unread,
            'items' => array_map(fn($n) => ['id' => $n['uuid'], 'type' => $n['type'], 'title' => $n['title'], 'body' => $n['body'], 'link' => $n['link'], 'read' => $n['read_at'] !== null, 'created_at' => $n['created_at']], $rows),
        ]);
    }

    public function read(Request $r): Response
    {
        $u = $this->user($r);
        $n = Notification::findByUuid((string) $r->param('id'));
        if (!$n || (int) $n['user_id'] !== (int) $u['id']) {
            throw HttpException::notFound('Notification');
        }
        Notification::updateById((int) $n['id'], ['read_at' => gmdate('Y-m-d H:i:s')]);
        return $this->ok(['read' => true]);
    }

    public function readAll(Request $r): Response
    {
        Database::execute('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [$this->user($r)['id']]);
        return $this->ok(['read' => true]);
    }
}
