<?php

declare(strict_types=1);

namespace App\View;

use RuntimeException;

/**
 * Plain-PHP templating. Templates live in /templates and are rendered with an
 * isolated variable scope; a template is wrapped in /templates/layout.php (the
 * rendered body is available there as $content). No business logic in templates —
 * controllers/services prepare the data; templates only present it (escaping via
 * the global e() helper).
 */
final class View
{
    private static string $dir;

    private static function dir(): string
    {
        return self::$dir ??= dirname(__DIR__, 2) . '/templates';
    }

    /** Render a template inside the layout and return the full HTML page. */
    public static function page(string $template, array $vars = [], array $layout = []): string
    {
        $content = self::partial($template, $vars);
        return self::partial('layout', $layout + ['content' => $content]);
    }

    /** Render a template fragment (no layout) and return it as a string. */
    public static function partial(string $template, array $vars = []): string
    {
        $file = self::dir() . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        $render = static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        };

        return $render($file, $vars);
    }
}
