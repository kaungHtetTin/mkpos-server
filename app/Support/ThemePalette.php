<?php

namespace App\Support;

class ThemePalette
{
    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    public static function mix(string $a, string $b, float $amount): string
    {
        $rgb = [];
        foreach ([1, 3, 5] as $offset) {
            $rgb[] = (int) round(hexdec(substr($a, $offset, 2)) * (1 - $amount) + hexdec(substr($b, $offset, 2)) * $amount);
        }

        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    public static function luminance(string $hex): float
    {
        $rgb = array_map(function ($offset) use ($hex) {
            $v = hexdec(substr($hex, $offset, 2)) / 255;

            return $v <= .04045 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4;
        }, [1, 3, 5]);

        return .2126 * $rgb[0] + .7152 * $rgb[1] + .0722 * $rgb[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $x = self::luminance($a);
        $y = self::luminance($b);

        return (max($x, $y) + .05) / (min($x, $y) + .05);
    }

    public static function readable(string $color, string $background, float $ratio = 4.5): string
    {
        $target = self::contrast('#ffffff', $background) > self::contrast('#000000', $background) ? '#ffffff' : '#000000';
        for ($i = 0; $i <= 100; $i++) {
            $candidate = self::mix($color, $target, $i / 100);
            if (self::contrast($candidate, $background) >= $ratio) {
                return $candidate;
            }
        }

        return $target;
    }

    public static function generate(string $primary, array $seeds = []): array
    {
        $primary = strtolower($primary);
        $seeds += ['secondary' => '#6356a5', 'neutral' => self::mix($primary, '#687078', .85), 'success' => '#168252', 'warning' => '#bd790e', 'danger' => '#c23a4b', 'info' => '#3577b8'];
        $palettes = [];
        foreach (['light', 'dark'] as $mode) {
            $dark = $mode === 'dark';
            $neutral = $seeds['neutral'];
            $surface = self::mix($neutral, $dark ? '#080b10' : '#ffffff', $dark ? .78 : .985);
            $background = self::mix($neutral, $dark ? '#05080c' : '#ffffff', $dark ? .87 : .94);
            $text = self::readable(self::mix($neutral, $dark ? '#ffffff' : '#000000', .85), $surface, 7);
            $sidebar = self::mix($primary, '#090e16', .72);
            $onPrimary = self::contrast('#ffffff', $primary) >= self::contrast('#000000', $primary) ? '#ffffff' : '#000000';
            $p = [
                'primary' => $primary, 'on_primary' => $onPrimary,
                'primary_hover' => self::mix($primary, $onPrimary === '#ffffff' ? '#000000' : '#ffffff', .16),
                'primary_soft' => self::mix($primary, $surface, $dark ? .78 : .90),
                'background' => $background, 'surface' => $surface,
                'surface_raised' => self::mix($neutral, $dark ? '#11161e' : '#ffffff', $dark ? .67 : .97),
                'surface_muted' => self::mix($neutral, $dark ? '#10151c' : '#ffffff', $dark ? .55 : .90),
                'text' => $text, 'text_muted' => self::readable(self::mix($text, $surface, .30), $surface, 4.8),
                'text_subtle' => self::readable(self::mix($text, $surface, .37), $surface),
                'border' => self::mix($neutral, $surface, $dark ? .35 : .70),
                'border_strong' => self::mix($neutral, $surface, $dark ? .15 : .38),
                'focus' => self::readable($primary, $surface, 3),
                'sidebar' => $sidebar, 'sidebar_text' => self::readable('#f8fafc', $sidebar, 7),
                'sidebar_muted' => self::readable(self::mix($primary, '#ffffff', .65), $sidebar),
                'sidebar_selected' => self::mix($primary, '#ffffff', .86),
                'inverse_surface' => self::mix($neutral, '#000000', .8), 'inverse_text' => '#ffffff',
                'shadow' => self::mix($neutral, '#000000', .8), 'overlay' => '#000000',
                'document_paper' => '#ffffff', 'document_ink' => '#111111',
                'disabled' => self::mix($neutral, $surface, .78), 'disabled_text' => self::readable(self::mix($text, $surface, .4), $surface),
            ];
            $p['primary_text'] = self::readable($primary, $p['primary_soft']);
            $p['sidebar_selected_text'] = self::readable($primary, $p['sidebar_selected']);
            foreach (['secondary', 'success', 'warning', 'danger', 'info'] as $role) {
                $p[$role] = self::readable($seeds[$role], $surface, 3);
                $p[$role.'_soft'] = self::mix($seeds[$role], $surface, $dark ? .82 : .91);
                $p[$role.'_text'] = self::readable($seeds[$role], $p[$role.'_soft']);
                $p['on_'.$role] = self::contrast('#ffffff', $p[$role]) >= self::contrast('#000000', $p[$role]) ? '#ffffff' : '#000000';
            }
            $p['link'] = self::readable($primary, $surface);
            foreach ([$primary, $seeds['secondary'], $seeds['info'], $seeds['success'], $seeds['warning'], $seeds['danger']] as $index => $color) {
                $p['chart_'.($index + 1)] = self::readable($color, $surface, 3);
            }
            $palettes[$mode] = $p;
        }

        return $palettes;
    }
}
