<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AccessibilityColorContrastTest extends TestCase
{
    public function test_global_text_tokens_meet_wcag_aa_on_their_light_surfaces(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        $primaryText = $this->token($css, '--color-primary-text');
        $primarySoft = $this->token($css, '--color-primary-soft');
        $muted = $this->token($css, '--color-muted');
        $page = $this->token($css, '--color-page');
        $surface = $this->token($css, '--color-surface');

        $this->assertGreaterThanOrEqual(4.5, $this->contrast($primaryText, $primarySoft));
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($muted, $page));
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($muted, $surface));
    }

    private function token(string $css, string $name): string
    {
        preg_match('/'.preg_quote($name, '/').'\s*:\s*(#[0-9a-f]{6})/i', $css, $matches);
        $this->assertArrayHasKey(1, $matches, "No se encontro el token {$name}.");

        return strtoupper($matches[1]);
    }

    private function contrast(string $foreground, string $background): float
    {
        $light = max($this->luminance($foreground), $this->luminance($background));
        $dark = min($this->luminance($foreground), $this->luminance($background));

        return ($light + 0.05) / ($dark + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(
            static fn (int $offset): float => hexdec(substr($hex, $offset, 2)) / 255,
            [1, 3, 5]
        );
        $channels = array_map(
            static fn (float $value): float => $value <= 0.04045
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4,
            $channels
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
