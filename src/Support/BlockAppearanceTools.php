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
 *
 * Two filters are used, deliberately. wp_theme_json_data_theme feeds the
 * frontend/site-editor global styles pipeline. block_editor_settings_all
 * feeds the post editor's __experimentalFeatures tree directly - the actual
 * gate the Inspector reads to decide which controls to show. The latter is
 * evaluated fresh on every editor load and is immune to the theme.json
 * resolution/caching quirks that, on a classic theme, can otherwise stop the
 * first filter's effect from ever reaching the editor (the reason bold/
 * italic/border controls kept not showing up despite complete block.json
 * supports).
 *
 * The block_editor_settings_all filter runs at PHP_INT_MAX so it wins even
 * when WordPress (as of 7.0) repopulates __experimentalFeatures from
 * wp_get_global_settings() at a late priority - a default-priority filter's
 * flags were being overwritten, which is what made Appearance/Decoration/
 * Letter-spacing vanish from the Typography options menu after upgrading.
 * The merge is recursive so an existing typography/border/color/spacing tree
 * is extended, not replaced, and our leaf flags are never clobbered.
 */
final class BlockAppearanceTools
{
    /**
     * The individual controls to force on, expressed as the same leaf flags
     * both filters need. appearanceTools (a theme.json shorthand) covers
     * border/link-color/spacing but NOT the typography styles below, so those
     * are always listed explicitly.
     */
    private const TYPOGRAPHY_FLAGS = [
        'fontStyle' => true,
        'fontWeight' => true,
        'textDecoration' => true,
        'letterSpacing' => true,
        'textTransform' => true,
    ];

    /**
     * The leaf flags to force into __experimentalFeatures, as the nested
     * shape WordPress resolves theme.json settings into.
     */
    private const EDITOR_FEATURE_FLAGS = [
        'appearanceTools' => true,
        'typography' => self::TYPOGRAPHY_FLAGS,
        'border' => [
            'color' => true,
            'radius' => true,
            'style' => true,
            'width' => true,
        ],
        'color' => [
            'link' => true,
        ],
        'spacing' => [
            'padding' => true,
            'margin' => true,
        ],
    ];

    public function boot(): void
    {
        add_filter('wp_theme_json_data_theme', [$this, 'forceEnableAppearanceControls']);
        add_filter('block_editor_settings_all', [$this, 'forceEditorControls'], PHP_INT_MAX);
    }

    public function forceEnableAppearanceControls(\WP_Theme_JSON_Data $themeJson): \WP_Theme_JSON_Data
    {
        return $themeJson->update_with(
            [
                // Version 2 (not 3): covers every setting used here and is
                // valid on every WP that supports the filter, avoiding a
                // silent drop on any resolution path that predates v3.
                'version' => 2,
                'settings' => [
                    'appearanceTools' => true,
                    'typography' => self::TYPOGRAPHY_FLAGS,
                ],
            ]
        );
    }

    /**
     * Recursively merges our leaf flags into the editor's resolved settings
     * tree, never replacing __experimentalFeatures wholesale - so a theme's
     * own palette, font sizes, spacing scale, etc. are all left untouched,
     * while our forced flags always win (they're applied last, at the deepest
     * level).
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function forceEditorControls(array $settings): array
    {
        $features = isset($settings['__experimentalFeatures']) && is_array($settings['__experimentalFeatures'])
            ? $settings['__experimentalFeatures']
            : [];

        $settings['__experimentalFeatures'] = $this->deepMerge($features, self::EDITOR_FEATURE_FLAGS);

        return $settings;
    }

    /**
     * Like array_merge_recursive, but scalar overrides replace rather than
     * accumulate into arrays (so `fontWeight => true` stays `true`, never
     * becomes `[false, true]`).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
