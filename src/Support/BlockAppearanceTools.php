<?php

declare(strict_types=1);

namespace EventMesh\Support;

/**
 * A block's own block.json `supports` only says it's *capable* of a
 * feature (bold/italic, underline, letter-spacing, border, link color) -
 * whether the Inspector actually shows the control for it is gated by the
 * site's merged global settings tree (theme.json + WP's classic-theme
 * defaults). A classic theme with no theme.json defaults border/link-color
 * off entirely, and appearanceTools (the bundled opt-in) doesn't cover
 * fontStyle/fontWeight/textDecoration/letterSpacing/textTransform - those
 * need setting individually. Forcing all of it on here means our blocks'
 * style controls work the same regardless of the active theme.
 */
final class BlockAppearanceTools
{
    public function boot(): void
    {
        add_filter('wp_theme_json_data_theme', [$this, 'forceEnableAppearanceControls']);
    }

    public function forceEnableAppearanceControls(\WP_Theme_JSON_Data $themeJson): \WP_Theme_JSON_Data
    {
        return $themeJson->update_with(
            [
                'version' => 3,
                'settings' => [
                    'appearanceTools' => true,
                    'typography' => [
                        'fontStyle' => true,
                        'fontWeight' => true,
                        'textDecoration' => true,
                        'letterSpacing' => true,
                        'textTransform' => true,
                    ],
                ],
            ]
        );
    }
}
