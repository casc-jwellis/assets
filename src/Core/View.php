<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /**
     * Render a template file, optionally wrapped in templates/layout-<layout>.php.
     * Inside templates, always output dynamic values through e().
     */
    public function render(string $file, array $data = [], ?string $layout = 'app'): string
    {
        $content = $this->capture($file, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->capture(APP_ROOT . '/templates/layout-' . $layout . '.php', $data + ['content' => $content]);
    }

    private function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
