<?php

declare(strict_types=1);

namespace Aio\Web;

/**
 * Server-side templates (templates/*.php, outside the web root). Every value
 * printed by a template goes through View::e() (MS-SEC-05 output escaping);
 * templates get it as $e. No template prints raw input.
 */
final class View
{
    public function __construct(private readonly string $dir)
    {
    }

    public static function e(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            $v = $v ? 'yes' : 'no';
        }
        return htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Renders templates/$name.php with $v, inside templates/layout.php unless
     * $layout is null.
     *
     * @param array<string, mixed> $v
     */
    public function render(string $name, array $v, ?string $layout = 'layout'): string
    {
        $body = $this->include($name, $v);
        return $layout === null ? $body : $this->include($layout, ['content' => $body] + $v);
    }

    /** @param array<string, mixed> $v */
    private function include(string $name, array $v): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException('bad template name');
        }
        $file = $this->dir . '/' . $name . '.php';
        $e = [self::class, 'e'];
        ob_start();
        try {
            (static function (string $__file, array $v, callable $e): void {
                include $__file;
            })($file, $v, $e);
            return (string) ob_get_clean();
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
    }
}
