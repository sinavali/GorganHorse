<?php
declare(strict_types=1);

/**
 * File: app/Services/ViewService.php
 *
 * Purpose:
 *   Minimal PHP template renderer. Renders a template under app/Views into a
 *   layout, exposing view data as local variables plus a `$view` escapers
 *   bundle. No template engine is used (Technical §5.4, §26.2).
 *
 * Dependencies: CultureService (for formatting helpers in views).
 *
 * @package App\Services
 */

namespace App\Services;

use App\Bootstrap\Container;

/**
 * Class: ViewService
 *
 * Purpose: Render server-side PHP templates inside a layout.
 */
final class ViewService
{
    private string $viewsDir;
    private Container $c;

    /**
     * @param Container $c        Service container.
     * @param string    $viewsDir Absolute views directory.
     */
    public function __construct(Container $c, string $viewsDir)
    {
        $this->c = $c;
        $this->viewsDir = rtrim($viewsDir, '/');
    }

    /**
     * Render a template inside a layout.
     *
     * @param string $template Template path without extension (e.g. "panel/users").
     * @param array  $data     Variables exposed to the template.
     * @param string $layout   Layout name (panel|auth|print).
     * @return string Rendered HTML.
     */
    public function render(string $template, array $data = [], string $layout = 'panel'): string
    {
        $content = $this->renderPartial($template, $data);
        $layoutFile = $this->viewsDir . '/layouts/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            return $content;
        }
        $data['content'] = $content;
        return $this->renderPartial('layouts/' . $layout, $data);
    }

    /**
     * Render a partial template and return its output.
     *
     * @param string $template Template path without extension.
     * @param array  $data     Variables.
     * @return string
     */
    public function renderPartial(string $template, array $data = []): string
    {
        $file = $this->viewsDir . '/' . $template . '.php';
        if (!is_file($file)) {
            return '<!-- missing view: ' . e($template) . ' -->';
        }
        $culture = $this->c->get('culture');
        $settings = $this->c->get('settings');
        $view = [
            'e' => static fn (mixed $v): string => e($v),
            'money' => static fn (int|float|null $v, bool $l = true): string => $culture->money($v, $l),
            'date' => static fn (?string $v, bool $t = false): string => $culture->formatDate($v, $t),
            'jalali' => static fn (?string $v): string => $culture->jalali($v),
            'num' => static fn (int|float|null $v, int $d = 0): string => $culture->number($v, $d),
            'settings' => $settings,
            'culture' => $culture,
            'dir' => $culture->direction(),
        ];
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
