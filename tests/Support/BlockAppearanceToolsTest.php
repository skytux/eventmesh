<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use EventMesh\Support\BlockAppearanceTools;
use EventMesh\Tests\TestCase;

final class BlockAppearanceToolsTest extends TestCase
{
    public function testForceEnableAppearanceControlsSetsAppearanceToolsAndTypographyFlags(): void
    {
        $themeJson = new \WP_Theme_JSON_Data();

        $result = (new BlockAppearanceTools())->forceEnableAppearanceControls($themeJson);

        $data = $result->get_data();

        self::assertTrue($data['settings']['appearanceTools']);
        self::assertTrue($data['settings']['typography']['fontStyle']);
        self::assertTrue($data['settings']['typography']['fontWeight']);
        self::assertTrue($data['settings']['typography']['textDecoration']);
        self::assertTrue($data['settings']['typography']['letterSpacing']);
        self::assertTrue($data['settings']['typography']['textTransform']);
    }

    public function testForceEnableAppearanceControlsPreservesExistingData(): void
    {
        $themeJson = new \WP_Theme_JSON_Data(['settings' => ['color' => ['palette' => ['custom']]]]);

        $result = (new BlockAppearanceTools())->forceEnableAppearanceControls($themeJson);

        $data = $result->get_data();

        self::assertSame(['custom'], $data['settings']['color']['palette']);
        self::assertTrue($data['settings']['appearanceTools']);
    }

    public function testForceEditorControlsEnablesTypographyBorderColorAndSpacingFlags(): void
    {
        $settings = (new BlockAppearanceTools())->forceEditorControls([]);

        $features = $settings['__experimentalFeatures'];

        self::assertTrue($features['typography']['fontStyle']);
        self::assertTrue($features['typography']['fontWeight']);
        self::assertTrue($features['typography']['textDecoration']);
        self::assertTrue($features['typography']['letterSpacing']);
        self::assertTrue($features['typography']['textTransform']);
        self::assertTrue($features['border']['color']);
        self::assertTrue($features['border']['radius']);
        self::assertTrue($features['border']['style']);
        self::assertTrue($features['border']['width']);
        self::assertTrue($features['color']['link']);
        self::assertTrue($features['spacing']['padding']);
        self::assertTrue($features['spacing']['margin']);
    }

    public function testForceEditorControlsPreservesUnrelatedEditorSettingsAndExistingFeatures(): void
    {
        $settings = (new BlockAppearanceTools())->forceEditorControls(
            [
                'styles' => ['keep-me'],
                '__experimentalFeatures' => [
                    'color' => ['palette' => ['theme' => ['brand']]],
                    'typography' => ['fontSizes' => ['big']],
                ],
            ]
        );

        // Unrelated top-level editor settings are untouched.
        self::assertSame(['keep-me'], $settings['styles']);

        $features = $settings['__experimentalFeatures'];

        // Existing feature data is merged, not clobbered.
        self::assertSame(['theme' => ['brand']], $features['color']['palette']);
        self::assertTrue($features['color']['link']);
        self::assertSame(['big'], $features['typography']['fontSizes']);
        self::assertTrue($features['typography']['fontWeight']);
    }
}
