<?php
declare(strict_types=1);

namespace App\Support;

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, $data + ['content' => $content]);
    }

    private static function capture(string $view, array $data): string
    {
        $file = Config::basePath('resources/views/' . $view . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
