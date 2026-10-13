<?php

declare(strict_types=1);

namespace App\Modules\Theme;

/**
 * The colour model behind the Theme page.
 *
 * An administrator chooses five colours per mode (light and dark): accent, page background, cards, sidebar
 * and text. Every other colour the stylesheet uses (hover, borders, muted text, tints) is worked out from those
 * five, and combinations that would be hard to read are refused. The same formulas are in
 * public/static/js/theme.js for the live preview; change them together.
 */
final class ThemeColors
{
    /** role => label, in the order the page shows them */
    public const ROLES = [
        'accent'  => 'Accent (buttons, links, active items)',
        'bg'      => 'Page background',
        'surface' => 'Cards and panels',
        'sidebar' => 'Sidebar',
        'text'    => 'Text',
    ];

    /** The palette that matches the built-in stylesheet: choosing it removes all overrides. */
    public const DEFAULT = 'indigo';

    /** Smallest contrast ratio (WCAG AA for normal text) between text/links and what they sit on. */
    public const MIN_CONTRAST = 4.5;

    public const PALETTES = [
        'indigo' => ['label' => 'Indigo (default)',
            'light' => ['accent' => '#4f46e5', 'bg' => '#f5f6f8', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#1a2230'],
            'dark'  => ['accent' => '#818cf8', 'bg' => '#0d1117', 'surface' => '#161b22', 'sidebar' => '#12171e', 'text' => '#e6e9ef']],
        'ocean' => ['label' => 'Ocean',
            'light' => ['accent' => '#0369a1', 'bg' => '#f3f7fa', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#12202b'],
            'dark'  => ['accent' => '#38bdf8', 'bg' => '#0a1620', 'surface' => '#0f2230', 'sidebar' => '#0c1c29', 'text' => '#e4eef6']],
        'teal' => ['label' => 'Teal',
            'light' => ['accent' => '#0f766e', 'bg' => '#f2f7f6', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#132321'],
            'dark'  => ['accent' => '#2dd4bf', 'bg' => '#0a1715', 'surface' => '#10231f', 'sidebar' => '#0d1d1a', 'text' => '#e2f1ee']],
        'forest' => ['label' => 'Forest',
            'light' => ['accent' => '#166534', 'bg' => '#f4f7f3', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#15231a'],
            'dark'  => ['accent' => '#4ade80', 'bg' => '#0b150e', 'surface' => '#122019', 'sidebar' => '#0e1a13', 'text' => '#e3f0e6']],
        'sunset' => ['label' => 'Sunset',
            'light' => ['accent' => '#b93d0a', 'bg' => '#fbf6f1', 'surface' => '#ffffff', 'sidebar' => '#fffaf5', 'text' => '#2a1a10'],
            'dark'  => ['accent' => '#fb923c', 'bg' => '#170f0a', 'surface' => '#21160e', 'sidebar' => '#1c120b', 'text' => '#f4e8de']],
        'crimson' => ['label' => 'Crimson',
            'light' => ['accent' => '#b91c1c', 'bg' => '#f8f4f4', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#261616'],
            'dark'  => ['accent' => '#f87171', 'bg' => '#160b0b', 'surface' => '#211212', 'sidebar' => '#1b0f0f', 'text' => '#f3e6e6']],
        'rose' => ['label' => 'Rose',
            'light' => ['accent' => '#be185d', 'bg' => '#faf4f6', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#2a1520'],
            'dark'  => ['accent' => '#f472b6', 'bg' => '#170b11', 'surface' => '#22121a', 'sidebar' => '#1c0e15', 'text' => '#f5e6ed']],
        'violet' => ['label' => 'Violet',
            'light' => ['accent' => '#7e22ce', 'bg' => '#f7f4fa', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#1f1530'],
            'dark'  => ['accent' => '#c084fc', 'bg' => '#120b1a', 'surface' => '#1b1226', 'sidebar' => '#160e20', 'text' => '#eee6f6']],
        'slate' => ['label' => 'Slate',
            'light' => ['accent' => '#475569', 'bg' => '#f1f3f5', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#111827'],
            'dark'  => ['accent' => '#94a3b8', 'bg' => '#0f1115', 'surface' => '#181b21', 'sidebar' => '#13161b', 'text' => '#e5e7eb']],
        'contrast' => ['label' => 'High contrast',
            'light' => ['accent' => '#0000cc', 'bg' => '#ffffff', 'surface' => '#ffffff', 'sidebar' => '#ffffff', 'text' => '#000000'],
            'dark'  => ['accent' => '#ffd60a', 'bg' => '#000000', 'surface' => '#0a0a0a', 'sidebar' => '#000000', 'text' => '#ffffff']],
    ];

    // ------------------------------------------------------------------ validating

    public static function isHex(mixed $v): bool
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1;
    }

    /**
     * Turn posted/stored values into ['light' => [role => '#rrggbb'], 'dark' => [...]], lower-cased.
     * Returns null unless every role of both modes holds a valid #rrggbb colour.
     */
    public static function normalize(mixed $light, mixed $dark): ?array
    {
        $out = [];
        foreach (['light' => $light, 'dark' => $dark] as $mode => $values) {
            if (!is_array($values)) {
                return null;
            }
            foreach (array_keys(self::ROLES) as $role) {
                if (!self::isHex($values[$role] ?? null)) {
                    return null;
                }
                $out[$mode][$role] = strtolower($values[$role]);
            }
        }
        return $out;
    }

    /**
     * Readability checks for one mode.
     *
     * @return list<array{label:string, ratio:float, ok:bool}>
     */
    public static function checks(array $c, bool $dark): array
    {
        $d = self::derive($c, $dark);
        $pairs = [
            ['Text on the page background', $c['text'], $c['bg']],
            ['Text on cards', $c['text'], $c['surface']],
            ['Text on the sidebar', $c['text'], $c['sidebar']],
            ['Accent on the page background', $c['accent'], $c['bg']],
            ['Accent on cards', $c['accent'], $c['surface']],
            ['Accent on the sidebar', $c['accent'], $c['sidebar']],
            ['Accent on the highlighted menu item', $c['accent'], $d['accent-soft']],
        ];
        return array_map(static function (array $p): array {
            $ratio = self::contrast($p[1], $p[2]);
            return ['label' => $p[0], 'ratio' => $ratio, 'ok' => $ratio >= self::MIN_CONTRAST];
        }, $pairs);
    }

    /** @return list<string> a message for each readability problem in either mode */
    public static function problems(array $colors): array
    {
        $out = [];
        foreach (['light' => 'Light', 'dark' => 'Dark'] as $mode => $name) {
            foreach (self::checks($colors[$mode], $mode === 'dark') as $check) {
                if (!$check['ok']) {
                    $out[] = sprintf('%s mode: "%s" is hard to read (contrast %.1f:1, it needs at least %.1f:1).',
                        $name, $check['label'], $check['ratio'], self::MIN_CONTRAST);
                }
            }
        }
        return $out;
    }

    /** The key of the pre-built palette these colours match exactly, or null for a custom set. */
    public static function matchingPalette(array $colors): ?string
    {
        foreach (self::PALETTES as $key => $p) {
            if ($p['light'] === $colors['light'] && $p['dark'] === $colors['dark']) {
                return $key;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ producing CSS

    /**
     * Every CSS variable the stylesheet derives from the five chosen colours.
     *
     * @return array<string,string> variable name (without --) => value
     */
    public static function derive(array $c, bool $dark): array
    {
        $surface2 = self::mix($c['surface'], $c['text'], $dark ? 0.04 : 0.06);
        $muted = $c['text'];
        for ($i = 10; $i <= 20; $i++) { // the lightest mix of text and background that is still easy to read
            $m = self::mix($c['bg'], $c['text'], $i / 20);
            if (self::contrast($m, $c['bg']) >= self::MIN_CONTRAST && self::contrast($m, $c['surface']) >= self::MIN_CONTRAST
                && self::contrast($m, $surface2) >= self::MIN_CONTRAST) {
                $muted = $m;
                break;
            }
        }
        return [
            'bg'              => $c['bg'],
            'surface'         => $c['surface'],
            'surface-2'       => $surface2,
            'border'          => self::mix($c['surface'], $c['text'], $dark ? 0.11 : 0.12),
            'text'            => $c['text'],
            'muted'           => $muted,
            'accent'          => $c['accent'],
            'accent-hover'    => $dark ? self::mix($c['accent'], '#ffffff', 0.22) : self::mix($c['accent'], '#000000', 0.18),
            'accent-contrast' => self::contrast($c['accent'], '#ffffff') >= self::contrast($c['accent'], '#0d1117') ? '#ffffff' : '#0d1117',
            'accent-soft'     => self::mix($c['surface'], $c['accent'], $dark ? 0.14 : 0.09),
            'sidebar-bg'      => $c['sidebar'],
        ];
    }

    /** The stylesheet that overrides the built-in colours (loaded after app.css). */
    public static function css(array $colors): string
    {
        $out = "/* Generated by the Theme page. Do not edit: change it there. */\n";
        foreach (['light' => ":root,\n:root[data-theme=\"light\"]", 'dark' => ':root[data-theme="dark"]'] as $mode => $selector) {
            $out .= $selector . " {\n";
            foreach (self::derive($colors[$mode], $mode === 'dark') as $name => $value) {
                $out .= "    --{$name}: {$value};\n";
            }
            $out .= "}\n";
        }
        return $out;
    }

    // ------------------------------------------------------------------ colour maths (mirrored in theme.js)

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** $a * (1 - $t) + $b * $t, per channel */
    public static function mix(string $a, string $b, float $t): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', max(0, min(255, (int) round($x[$i] * (1 - $t) + $y[$i] * $t))));
        }
        return $out;
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
