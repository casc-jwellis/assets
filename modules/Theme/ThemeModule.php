<?php

declare(strict_types=1);

namespace App\Modules\Theme;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * Theme (administrators only): the site name and the colours, with pre-built palettes.
 *
 * Settings live in the `settings` table (migration 015): site.name, theme.palette, theme.colors (JSON) and
 * theme.version. The colours reach every page, the login page included, as a small generated stylesheet at
 * /theme/css that is linked after app.css (the page's content security policy allows no inline styles).
 * Remove this folder and the app goes back to the built-in look and the setup-time name.
 */
final class ThemeModule extends Module
{
    public const MIGRATION = '015_settings.sql';
    private const NAME_MAX = 64;

    public function routes(Router $router): void
    {
        $admin = ['admin' => true];
        $router->get('/theme', [$this, 'show'], $admin);
        $router->post('/theme', [$this, 'save'], $admin);
        $router->post('/theme/reset', [$this, 'reset'], $admin);
        // Public: the login page needs the colours too. It reveals nothing but a handful of colour values.
        $router->get('/theme/css', [$this, 'css'], ['public' => true]);
        // Public too: browsers fetch the tab icon on the login page as well.
        $router->get('/theme/favicon.svg', [$this, 'favicon'], ['public' => true]);
    }

    public function nav(): array
    {
        return [['label' => 'Theme', 'path' => '/theme', 'icon' => 'droplet', 'order' => 85, 'admin' => true]];
    }

    // ------------------------------------------------------------ the page

    public function show(Request $req): string
    {
        return $this->form([
            'name'    => app_name(),
            'palette' => $this->currentPalette(),
            'colors'  => $this->currentColors(),
        ], []);
    }

    public function save(Request $req): string
    {
        $this->requireReady();
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $req->post('site_name')));
        $colors = ThemeColors::normalize($req->postArray('light'), $req->postArray('dark'));
        $errors = [];

        if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            $errors[] = 'Enter a site name of up to ' . self::NAME_MAX . ' characters.';
        }
        if ($colors === null) {
            $errors[] = 'Every colour must be a valid colour (like #4f46e5).';
        } else {
            array_push($errors, ...ThemeColors::problems($colors));
        }

        if ($errors !== []) {
            http_response_code(422);
            $posted = $colors ?? $this->currentColors();
            return $this->form([
                'name'    => $name,
                'palette' => ThemeColors::matchingPalette($posted) ?? 'custom',
                'colors'  => $posted,
            ], $errors);
        }

        $settings = $this->app->settings;
        try {
            $settings->set('site.name', $name);
            $palette = ThemeColors::matchingPalette($colors);
            if ($palette === ThemeColors::DEFAULT) {
                // The built-in stylesheet already is this palette: nothing to override.
                $settings->delete('theme.palette', 'theme.colors', 'theme.version');
            } else {
                $json = (string) json_encode($colors);
                $settings->set('theme.palette', $palette ?? 'custom');
                $settings->set('theme.colors', $json);
                $settings->set('theme.version', substr(md5($json), 0, 12));
            }
        } catch (\PDOException $e) {
            error_log('theme save failed: ' . $e->getMessage());
            http_response_code(500);
            return $this->form(['name' => $name, 'palette' => $palette ?? 'custom', 'colors' => $colors], ['The theme could not be saved because of a database error.']);
        }

        $this->app->session->flash('success', 'Theme saved.');
        $this->redirect('/theme');
    }

    /** Back to the built-in colours (the site name is left as it is). */
    public function reset(Request $req): never
    {
        $this->requireReady();
        $this->app->settings->delete('theme.palette', 'theme.colors', 'theme.version');
        $this->app->session->flash('success', 'The colours are back to the default palette.');
        $this->redirect('/theme');
    }

    // ------------------------------------------------------------ the stylesheet

    public function css(Request $req): string
    {
        $colors = $this->storedColors();
        header('Content-Type: text/css; charset=utf-8');
        // The link carries ?v=<version>, so a changed theme is a new URL and this one can be cached for good.
        header_remove('Pragma');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        return $colors === null ? "/* No custom theme. */\n" : ThemeColors::css($colors);
    }

    /**
     * The tab icon in the theme's accent colour: the same box as the sidebar logo (accent background, accent-contrast
     * line). ?mode=light|dark picks that mode's accent, ?v=<theme version> only changes the URL when the theme does.
     */
    public function favicon(Request $req): string
    {
        $mode = $req->query('mode') === 'dark' ? 'dark' : 'light';
        $d = ThemeColors::derive($this->currentColors()[$mode], $mode === 'dark');
        header('Content-Type: image/svg+xml; charset=utf-8');
        if ((string) $req->query('v') !== '') {
            header_remove('Pragma');
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        }
        // Colours come out of ThemeColors::derive(): always well-formed #rrggbb values.
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="32" height="32" rx="7" fill="' . $d['accent'] . '"/>'
            . '<g transform="translate(6 6) scale(0.8333)" fill="none" stroke="' . $d['accent-contrast'] . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>'
            . '<polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></g></svg>';
    }

    // ------------------------------------------------------------ helpers

    private function ready(): bool
    {
        return $this->app->migrationApplied(self::MIGRATION);
    }

    private function requireReady(): void
    {
        if (!$this->ready()) {
            $this->app->error(503, 'The theme is not available yet', 'The database needs updating first: apply migration ' . self::MIGRATION . ' on the Migrations page.');
        }
    }

    /** The saved colours (re-validated: whatever is in the table, only well-formed colours are ever used), or null. */
    private function storedColors(): ?array
    {
        $raw = $this->app->settings->get('theme.colors');
        $data = $raw === null ? null : json_decode($raw, true);
        return is_array($data) ? ThemeColors::normalize($data['light'] ?? null, $data['dark'] ?? null) : null;
    }

    private function currentColors(): array
    {
        return $this->storedColors() ?? [
            'light' => ThemeColors::PALETTES[ThemeColors::DEFAULT]['light'],
            'dark'  => ThemeColors::PALETTES[ThemeColors::DEFAULT]['dark'],
        ];
    }

    private function currentPalette(): string
    {
        $colors = $this->storedColors();
        return $colors === null ? ThemeColors::DEFAULT : (ThemeColors::matchingPalette($colors) ?? 'custom');
    }

    private function form(array $values, array $errors): string
    {
        return $this->render('index', [
            'title'    => 'Theme',
            'ready'    => $this->ready(),
            'v'        => $values,
            'errors'   => $errors,
            'palettes' => ThemeColors::PALETTES,
            'roles'    => ThemeColors::ROLES,
            'nameMax'  => self::NAME_MAX,
            'minContrast' => ThemeColors::MIN_CONTRAST,
        ]);
    }
}
