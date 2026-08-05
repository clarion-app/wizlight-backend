<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Mode\ActiveMode;
use ClarionApp\WizlightBackend\Mode\AmbiguousModeException;

/**
 * Tests for ActiveMode resolution, inference, and fromPilot precedence.
 * Fixture-shaped payloads from contracts/scene-catalogue.md.
 */
class ActiveModeTest extends TestCase
{
    // ------------------------------------------------------------------
    // fromPilot() — precedence from research.md
    // ------------------------------------------------------------------

    /** @test */
    public function fromPilot_sceneId_greater_than_zero_returns_scene()
    {
        // F1: full-colour bulb playing an animated scene
        $payload = [
            'mac' => 'a8bb50000001',
            'state' => true,
            'sceneId' => 1,
            'dimming' => 80,
            'rssi' => -58,
        ];
        $this->assertSame(ActiveMode::SCENE, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_sceneId_takes_precedence_over_rgb()
    {
        // Even if r/g/b are present, sceneId > 0 wins.
        $payload = [
            'mac' => 'a8bb50000001',
            'sceneId' => 1,
            'r' => 255,
            'g' => 0,
            'b' => 0,
        ];
        $this->assertSame(ActiveMode::SCENE, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_sceneId_zero_does_not_trigger_scene()
    {
        // sceneId = 0 means "no scene" — should fall through to next rung.
        $payload = [
            'mac' => 'a8bb50000001',
            'sceneId' => 0,
            'r' => 100,
            'g' => 50,
            'b' => 200,
        ];
        $this->assertSame(ActiveMode::RGB, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_c_and_w_non_zero_returns_white_channels()
    {
        // F2: RGBWW bulb driven through white channels
        $payload = [
            'mac' => 'a8bb50000002',
            'state' => true,
            'c' => 200,
            'w' => 40,
            'dimming' => 100,
            'rssi' => -61,
        ];
        $this->assertSame(ActiveMode::WHITE_CHANNELS, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_c_or_w_non_zero_returns_white_channels()
    {
        // Only w (warm) non-zero
        $payload = [
            'mac' => 'a8bb50000002',
            'w' => 150,
            'c' => 0,
        ];
        $this->assertSame(ActiveMode::WHITE_CHANNELS, ActiveMode::fromPilot($payload));

        // Only c (cool) non-zero
        $payload = [
            'mac' => 'a8bb50000002',
            'c' => 100,
            'w' => 0,
        ];
        $this->assertSame(ActiveMode::WHITE_CHANNELS, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_c_and_w_zero_does_not_trigger_white_channels()
    {
        // Both zero — falls through.
        $payload = [
            'mac' => 'a8bb50000002',
            'c' => 0,
            'w' => 0,
            'temp' => 3000,
        ];
        $this->assertSame(ActiveMode::WARMTH, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_temp_greater_than_zero_returns_warmth()
    {
        $payload = [
            'mac' => 'a8bb50000001',
            'state' => true,
            'temp' => 2700,
            'dimming' => 100,
        ];
        $this->assertSame(ActiveMode::WARMTH, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_temp_zero_does_not_trigger_warmth()
    {
        // temp = 0 — falls through to rgb.
        $payload = [
            'mac' => 'a8bb50000001',
            'temp' => 0,
            'r' => 255,
            'g' => 128,
            'b' => 64,
        ];
        $this->assertSame(ActiveMode::RGB, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_r_g_b_present_returns_rgb()
    {
        $payload = [
            'mac' => 'a8bb50000001',
            'r' => 255,
            'g' => 128,
            'b' => 64,
        ];
        $this->assertSame(ActiveMode::RGB, ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_no_mode_signal_returns_null()
    {
        // Only state and dimming — no colour, temp, scene, or white channel signal.
        $payload = [
            'mac' => 'a8bb50000001',
            'state' => true,
            'dimming' => 80,
            'rssi' => -58,
        ];
        $this->assertNull(ActiveMode::fromPilot($payload));
    }

    /** @test */
    public function fromPilot_empty_payload_returns_null()
    {
        $this->assertNull(ActiveMode::fromPilot([]));
    }

    // ------------------------------------------------------------------
    // infer() — which single mode does this request change?
    // ------------------------------------------------------------------

    /** @test */
    public function infer_returns_rgb_when_rgb_fields_changed()
    {
        $requested = ['red' => 255, 'green' => 0, 'blue' => 0];
        $stored = ['red' => 100, 'green' => 100, 'blue' => 100];

        $this->assertSame(ActiveMode::RGB, ActiveMode::infer($requested, $stored));
    }

    /** @test */
    public function infer_returns_warmth_when_temperature_changed()
    {
        $requested = ['temperature' => 3000];
        $stored = ['temperature' => 2700];

        $this->assertSame(ActiveMode::WARMTH, ActiveMode::infer($requested, $stored));
    }

    /** @test */
    public function infer_returns_white_channels_when_warm_or_cool_changed()
    {
        $requested = ['white_warm' => 200, 'white_cool' => 40];
        $stored = ['white_warm' => 100, 'white_cool' => 100];

        $this->assertSame(ActiveMode::WHITE_CHANNELS, ActiveMode::infer($requested, $stored));
    }

    /** @test */
    public function infer_returns_scene_when_scene_id_changed()
    {
        $requested = ['scene_id' => 1];
        $stored = ['scene_id' => 3];

        $this->assertSame(ActiveMode::SCENE, ActiveMode::infer($requested, $stored));
    }

    /** @test */
    public function infer_returns_scene_when_scene_speed_changed()
    {
        $requested = ['scene_speed' => 150];
        $stored = ['scene_speed' => 100];

        $this->assertSame(ActiveMode::SCENE, ActiveMode::infer($requested, $stored));
    }

    /** @test */
    public function infer_returns_null_when_no_mode_fields_changed()
    {
        // Only orthogonal fields changed (state, dimming).
        $requested = ['state' => true, 'dimming' => 75];
        $stored = ['state' => false, 'dimming' => 50];

        $this->assertNull(ActiveMode::infer($requested, $stored));
    }

    /** @test */
    public function infer_returns_null_when_rgb_fields_present_but_unchanged()
    {
        // Frontend spreads whole bulb object — presence means nothing.
        $requested = ['red' => 100, 'green' => 100, 'blue' => 100, 'state' => true];
        $stored = ['red' => 100, 'green' => 100, 'blue' => 100, 'state' => false];

        $this->assertNull(ActiveMode::infer($requested, $stored));
    }

    /** @test */
    public function infer_throws_ambiguous_when_two_mode_groups_changed()
    {
        // Both RGB and warmth changed, no active_mode given.
        $requested = [
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'temperature' => 4000,
        ];
        $stored = [
            'red' => 100,
            'green' => 100,
            'blue' => 100,
            'temperature' => 2700,
        ];

        $this->expectException(AmbiguousModeException::class);
        ActiveMode::infer($requested, $stored);
    }

    /** @test */
    public function infer_throws_ambiguous_when_rgb_and_scene_changed()
    {
        $requested = [
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'scene_id' => 1,
        ];
        $stored = [
            'red' => 100,
            'green' => 100,
            'blue' => 100,
            'scene_id' => 3,
        ];

        $this->expectException(AmbiguousModeException::class);
        ActiveMode::infer($requested, $stored);
    }

    // ------------------------------------------------------------------
    // resolve() — stored value or legacy inference
    // ------------------------------------------------------------------

    /** @test */
    public function resolve_returns_stored_mode_when_not_null()
    {
        $this->assertSame(ActiveMode::SCENE, ActiveMode::resolve('scene', []));
        $this->assertSame(ActiveMode::RGB, ActiveMode::resolve('rgb', []));
        $this->assertSame(ActiveMode::WARMTH, ActiveMode::resolve('warmth', []));
        $this->assertSame(ActiveMode::WHITE_CHANNELS, ActiveMode::resolve('white_channels', []));
    }

    /** @test */
    public function resolve_legacy_warmth_when_rgb_zero_and_temp_positive()
    {
        // The exact condition the old buildCommand() used.
        $values = [
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'temperature' => 2700,
        ];
        $this->assertSame(
            ActiveMode::WARMTH,
            ActiveMode::resolve(null, $values),
            'Legacy row with RGB=0 and temp>0 should resolve to warmth'
        );
    }

    /** @test */
    public function resolve_legacy_rgb_when_rgb_non_zero()
    {
        $values = [
            'red' => 255,
            'green' => 0,
            'blue' => 0,
            'temperature' => 0,
        ];
        $this->assertSame(
            ActiveMode::RGB,
            ActiveMode::resolve(null, $values),
            'Legacy row with non-zero RGB should resolve to rgb'
        );
    }

    /** @test */
    public function resolve_legacy_rgb_when_all_rgb_zero_and_temp_zero()
    {
        // Fallback: all zero = rgb (old buildCommand default).
        $values = [
            'red' => 0,
            'green' => 0,
            'blue' => 0,
            'temperature' => 0,
        ];
        $this->assertSame(
            ActiveMode::RGB,
            ActiveMode::resolve(null, $values),
            'Legacy row with all-zero RGB and zero temp should resolve to rgb (default)'
        );
    }

    // ------------------------------------------------------------------
    // FIELD_GROUPS constant
    // ------------------------------------------------------------------

    /** @test */
    public function field_groups_constant_has_correct_shape()
    {
        $groups = ActiveMode::FIELD_GROUPS;
        $this->assertSame(['red', 'green', 'blue'], $groups[ActiveMode::RGB]);
        $this->assertSame(['temperature'], $groups[ActiveMode::WARMTH]);
        $this->assertSame(['white_warm', 'white_cool'], $groups[ActiveMode::WHITE_CHANNELS]);
        $this->assertSame(['scene_id', 'scene_speed'], $groups[ActiveMode::SCENE]);
    }

    // ------------------------------------------------------------------
    // AmbiguousModeException extends RuntimeException
    // ------------------------------------------------------------------

    /** @test */
    public function ambiguous_mode_exception_extends_runtime_exception()
    {
        try {
            ActiveMode::infer(
                ['red' => 255, 'temperature' => 3000],
                ['red' => 0, 'temperature' => 0]
            );
        } catch (AmbiguousModeException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e);
            return;
        }
        $this->fail('Expected AmbiguousModeException to be thrown');
    }
}
