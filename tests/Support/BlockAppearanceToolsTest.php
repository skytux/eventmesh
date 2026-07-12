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
}
