<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Capability\DeviceCapabilityValidator;
use ClarionApp\WizlightBackend\Capability\CapabilityClass;
use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Validation\ValidationException;

/**
 * One rejection / acceptance case per FR.
 * Uses PHPUnit mock builder to stub capability columns on Bulb.
 */
class DeviceCapabilityValidatorTest extends TestCase
{
    private function makeValidator(): DeviceCapabilityValidator
    {
        return new DeviceCapabilityValidator();
    }

    private function mockBulb(array $attrs): Bulb
    {
        $bulb = $this->getMockBuilder(Bulb::class)
            ->onlyMethods(['getAttribute'])
            ->getMock();

        $storage = [
            'id' => 'test-bulb-id',
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
            'min_brightness_pct' => 1,
        ];
        foreach ($attrs as $k => $v) {
            $storage[$k] = $v;
        }

        $bulb->method('getAttribute')->willReturnCallback(
            fn ($key) => $storage[$key] ?? null
        );

        return $bulb;
    }

    // ------------------------------------------------------------------
    // FR-008: Colour rejected on dim_only and tunable_white
    // ------------------------------------------------------------------

    /** @test */
    public function fr008_colour_rejected_on_dim_only()
    {
        $bulb = $this->mockBulb(['capability_class' => CapabilityClass::DIM_ONLY]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, [
            'red' => 255,
            'green' => 0,
            'blue' => 0,
        ]);
    }

    /** @test */
    public function fr008_colour_rejected_on_tunable_white()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, [
            'red' => 100,
            'green' => 50,
            'blue' => 25,
        ]);
    }

    /** @test */
    public function fr008_zero_colour_allowed_on_any_class()
    {
        $bulb = $this->mockBulb(['capability_class' => CapabilityClass::DIM_ONLY]);
        $validator = $this->makeValidator();

        // No exception — all-zero RGB is not a colour request.
        try {
            $validator->validate($bulb, [
                'red' => 0,
                'green' => 0,
                'blue' => 0,
                'dimming' => 50,
            ]);
            $this->assertTrue(true, 'All-zero colour must not raise on a dim_only device.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // FR-009: Temperature out of range, and on dim_only
    // ------------------------------------------------------------------

    /** @test */
    public function fr009_temperature_below_min_rejected()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['temperature' => 1800]);
    }

    /** @test */
    public function fr009_temperature_above_max_rejected()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['temperature' => 7000]);
    }

    /** @test */
    public function fr009_temperature_rejected_on_dim_only()
    {
        $bulb = $this->mockBulb(['capability_class' => CapabilityClass::DIM_ONLY]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['temperature' => 3000]);
    }

    // ------------------------------------------------------------------
    // FR-010: Dimming below min_brightness_pct
    // ------------------------------------------------------------------

    /** @test */
    public function fr010_dimming_below_min_rejected()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'min_brightness_pct' => 5,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['dimming' => 2]);
    }

    /** @test */
    public function fr010_dimming_zero_always_allowed()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::DIM_ONLY,
            'min_brightness_pct' => 5,
        ]);
        $validator = $this->makeValidator();

        // dimming === 0 means "off" — never rejected regardless of class or minimum.
        try {
            $validator->validate($bulb, ['dimming' => 0]);
            $this->assertTrue(true, 'dimming=0 must never be rejected.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    /** @test */
    public function fr010_dimming_at_min_accepted()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'min_brightness_pct' => 5,
        ]);
        $validator = $this->makeValidator();

        try {
            $validator->validate($bulb, ['dimming' => 5]);
            $this->assertTrue(true, 'dimming exactly at min_brightness_pct must be accepted.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // FR-013: Inclusive boundaries
    // ------------------------------------------------------------------

    /** @test */
    public function fr013_temperature_exactly_at_min_accepted()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $validator = $this->makeValidator();

        try {
            $validator->validate($bulb, ['temperature' => 2200]);
            $this->assertTrue(true, 'Temperature exactly at warmth_min_kelvin must be accepted.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    /** @test */
    public function fr013_temperature_exactly_at_max_accepted()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);
        $validator = $this->makeValidator();

        try {
            $validator->validate($bulb, ['temperature' => 6500]);
            $this->assertTrue(true, 'Temperature exactly at warmth_max_kelvin must be accepted.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // filterForRoom: mixed-capability room (FR-016)
    // ------------------------------------------------------------------

    /** @test */
    public function filterForRoom_returns_applicable_subset_per_bulb()
    {
        $validator = $this->makeValidator();

        $validated = [
            'state' => true,
            'red' => 255,
            'green' => 128,
            'blue' => 0,
            'temperature' => 4000,
            'dimming' => 75,
        ];

        // Full-colour bulb: everything passes.
        $fullColour = $this->mockBulb([
            'id' => 'bulb-full-colour',
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);
        $skips = [];
        $applicable = $validator->filterForRoom($fullColour, $validated, $skips);

        $this->assertEquals($validated, $applicable);
        $this->assertEmpty($skips);

        // Tunable-white bulb: colour fields dropped.
        $skips = [];
        $tunableWhite = $this->mockBulb([
            'id' => 'bulb-tunable-white',
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $applicable = $validator->filterForRoom($tunableWhite, $validated, $skips);

        $this->assertArrayHasKey('state', $applicable);
        $this->assertArrayHasKey('dimming', $applicable);
        $this->assertArrayHasKey('temperature', $applicable);
        $this->assertArrayNotHasKey('red', $applicable);
        $this->assertArrayNotHasKey('green', $applicable);
        $this->assertArrayNotHasKey('blue', $applicable);
        // Three colour fields should be in skips.
        $colourSkips = array_filter($skips, fn ($s) => in_array($s['field'], ['red', 'green', 'blue'], true));
        $this->assertCount(3, $colourSkips);
        foreach ($colourSkips as $skip) {
            $this->assertSame('bulb-tunable-white', $skip['bulb_id']);
        }

        // Dim-only bulb: colour and temperature dropped.
        $skips = [];
        $dimOnly = $this->mockBulb([
            'id' => 'bulb-dim-only',
            'capability_class' => CapabilityClass::DIM_ONLY,
        ]);
        $applicable = $validator->filterForRoom($dimOnly, $validated, $skips);

        $this->assertArrayHasKey('state', $applicable);
        $this->assertArrayHasKey('dimming', $applicable);
        $this->assertArrayNotHasKey('red', $applicable);
        $this->assertArrayNotHasKey('green', $applicable);
        $this->assertArrayNotHasKey('blue', $applicable);
        $this->assertArrayNotHasKey('temperature', $applicable);
        // Four fields should be in skips (red, green, blue, temperature).
        $this->assertCount(4, $skips);
        foreach ($skips as $skip) {
            $this->assertSame('bulb-dim-only', $skip['bulb_id']);
        }
    }

    // ------------------------------------------------------------------
    // FR-014 fallback: null and corrupted capability_class
    // ------------------------------------------------------------------

    /** @test */
    public function fr014_null_capability_class_rejects_colour_and_temperature()
    {
        $bulb = $this->mockBulb([
            'capability_class' => null,
            'warmth_min_kelvin' => null,
            'warmth_max_kelvin' => null,
        ]);
        $validator = $this->makeValidator();

        // Colour rejected — null reads as dim_only.
        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['red' => 255, 'green' => 0, 'blue' => 0]);
    }

    /** @test */
    public function fr014_null_capability_class_rejects_temperature()
    {
        $bulb = $this->mockBulb([
            'capability_class' => null,
            'warmth_min_kelvin' => null,
            'warmth_max_kelvin' => null,
        ]);
        $validator = $this->makeValidator();

        // Temperature rejected — null reads as dim_only (no range).
        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['temperature' => 3000]);
    }

    /** @test */
    public function fr014_corrupted_capability_class_rejects_identically()
    {
        $bulb = $this->mockBulb([
            'capability_class' => 'some_corrupted_string',
            'warmth_min_kelvin' => null,
            'warmth_max_kelvin' => null,
        ]);
        $validator = $this->makeValidator();

        // Unrecognized string degrades to dim_only — colour rejected.
        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['red' => 100, 'green' => 50, 'blue' => 25]);
    }

    /** @test */
    public function fr014_corrupted_capability_class_rejects_temperature()
    {
        $bulb = $this->mockBulb([
            'capability_class' => 'unknown_device_type',
            'warmth_min_kelvin' => null,
            'warmth_max_kelvin' => null,
        ]);
        $validator = $this->makeValidator();

        // Unrecognized string — temperature rejected.
        $this->expectException(ValidationException::class);

        $validator->validate($bulb, ['temperature' => 4000]);
    }

    // ------------------------------------------------------------------
    // active_mode rule: rgb/white_channels rejected on non-full_colour
    // ------------------------------------------------------------------

    /** @test */
    public function active_mode_rgb_rejected_on_tunable_white()
    {
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, [
            'active_mode' => 'rgb',
        ]);
    }

    /** @test */
    public function active_mode_rgb_rejected_on_dim_only()
    {
        $bulb = $this->mockBulb(['capability_class' => CapabilityClass::DIM_ONLY]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, [
            'active_mode' => 'rgb',
        ]);
    }

    /** @test */
    public function active_mode_white_channels_rejected_on_tunable_white()
    {
        // white_channels mode is only valid on dual-head full_colour devices.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, [
            'active_mode' => 'white_channels',
        ]);
    }

    /** @test */
    public function active_mode_warmth_rejected_on_dim_only()
    {
        $bulb = $this->mockBulb(['capability_class' => CapabilityClass::DIM_ONLY]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        $validator->validate($bulb, [
            'active_mode' => 'warmth',
        ]);
    }

    // ------------------------------------------------------------------
    // filterForRoom: active_mode field filtered for incompatible bulbs
    // ------------------------------------------------------------------

    /** @test */
    public function filterForRoom_records_skip_for_active_mode_on_incompatible_bulb()
    {
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-tunable-white-active-mode',
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);

        $validated = [
            'state' => true,
            'active_mode' => 'rgb',
            'dimming' => 75,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        // active_mode should be dropped from applicable values.
        $this->assertArrayNotHasKey('active_mode', $applicable);
        // And recorded as a skip.
        $activeModeSkips = array_filter($skips, fn ($s) => $s['field'] === 'active_mode');
        $this->assertCount(1, $activeModeSkips);
        $this->assertSame('bulb-tunable-white-active-mode', $activeModeSkips[0]['bulb_id']);
    }

    /** @test */
    public function filterForRoom_passes_active_mode_on_compatible_bulb()
    {
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-full-colour-active-mode',
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 6500,
        ]);

        $validated = [
            'active_mode' => 'rgb',
            'dimming' => 75,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        $this->assertArrayHasKey('active_mode', $applicable);
        $this->assertSame('rgb', $applicable['active_mode']);
        $activeModeSkips = array_filter($skips, fn ($s) => $s['field'] === 'active_mode');
        $this->assertCount(0, $activeModeSkips);
    }

    // ------------------------------------------------------------------
    // US1: scene_id rejected when unsupported by device class
    // ------------------------------------------------------------------

    /** @test */
    public function scene_id_rejected_on_tunable_white_when_unsupported()
    {
        // Ocean (1) is full_colour only. A tunable_white device must reject it.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        try {
            $validator->validate($bulb, ['scene_id' => 1]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('scene_id', $errors);
            // Error message should name the scene and the class.
            $this->assertStringContainsString('Ocean', $errors['scene_id'][0]);
            $this->assertStringContainsString('tunable_white', $errors['scene_id'][0]);
            throw $e;
        }
    }

    /** @test */
    public function scene_id_rejected_when_unknown()
    {
        // Scene 57 is not in the catalogue at all.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        try {
            $validator->validate($bulb, ['scene_id' => 57]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('scene_id', $errors);
            // Error message should reference the unknown ID.
            $this->assertStringContainsString('57', $errors['scene_id'][0]);
            throw $e;
        }
    }

    /** @test */
    public function scene_id_accepted_on_compatible_device()
    {
        // Ocean (1) is supported by full_colour devices.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
        ]);
        $validator = $this->makeValidator();

        try {
            $validator->validate($bulb, ['scene_id' => 1]);
            $this->assertTrue(true, 'scene_id=1 must be accepted on a full_colour device.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    /** @test */
    public function scene_id_accepted_on_tunable_white_when_supported()
    {
        // Wake-up (9) is supported by tunable_white devices.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);
        $validator = $this->makeValidator();

        try {
            $validator->validate($bulb, ['scene_id' => 9]);
            $this->assertTrue(true, 'scene_id=9 must be accepted on a tunable_white device.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // filterForRoom: scene_id filtered for incompatible bulbs
    // ------------------------------------------------------------------

    /** @test */
    public function filterForRoom_records_skip_for_scene_id_on_incompatible_bulb()
    {
        // Ocean (1) is full_colour only. A tunable_white bulb should skip it.
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-tw-scene-skip',
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);

        $validated = [
            'state' => true,
            'active_mode' => 'scene',
            'scene_id' => 1,
            'dimming' => 75,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        // scene_id should be dropped from applicable values.
        $this->assertArrayNotHasKey('scene_id', $applicable);
        // And recorded as a skip.
        $sceneSkips = array_filter($skips, fn ($s) => $s['field'] === 'scene_id');
        $this->assertCount(1, $sceneSkips);
        $this->assertSame('bulb-tw-scene-skip', $sceneSkips[0]['bulb_id']);
        // Skip reason should name the scene and the class.
        $this->assertStringContainsString('Ocean', $sceneSkips[0]['reason']);
        $this->assertStringContainsString('tunable_white', $sceneSkips[0]['reason']);
    }

    /** @test */
    public function filterForRoom_records_skip_for_unknown_scene_id()
    {
        // Scene 57 is not in the catalogue — even a full_colour bulb should skip it.
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-fc-unknown-scene',
            'capability_class' => CapabilityClass::FULL_COLOUR,
        ]);

        $validated = [
            'scene_id' => 57,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        $this->assertArrayNotHasKey('scene_id', $applicable);
        $sceneSkips = array_filter($skips, fn ($s) => $s['field'] === 'scene_id');
        $this->assertCount(1, $sceneSkips);
        $this->assertSame('bulb-fc-unknown-scene', $sceneSkips[0]['bulb_id']);
        // Skip reason should reference the unknown ID.
        $this->assertStringContainsString('57', $sceneSkips[0]['reason']);
    }

    /** @test */
    public function filterForRoom_passes_scene_id_on_compatible_bulb()
    {
        // Wake-up (9) is supported by tunable_white devices.
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-tw-scene-pass',
            'capability_class' => CapabilityClass::TUNABLE_WHITE,
            'warmth_min_kelvin' => 2200,
            'warmth_max_kelvin' => 5000,
        ]);

        $validated = [
            'scene_id' => 9,
            'dimming' => 75,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        $this->assertArrayHasKey('scene_id', $applicable);
        $this->assertSame(9, $applicable['scene_id']);
        $sceneSkips = array_filter($skips, fn ($s) => $s['field'] === 'scene_id');
        $this->assertCount(0, $sceneSkips);
    }

    // ------------------------------------------------------------------
    // US2: scene_speed rejected when effective scene is static or absent
    // ------------------------------------------------------------------

    /** @test */
    public function scene_speed_rejected_when_request_scene_is_static()
    {
        // Warm white (11) is a static scene. Speed should be rejected.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        try {
            $validator->validate($bulb, [
                'scene_id' => 11,
                'scene_speed' => 150,
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('scene_speed', $errors);
            // Error message should name the scene as not animated.
            $this->assertStringContainsString('Warm white', $errors['scene_speed'][0]);
            $this->assertStringContainsString('animated', $errors['scene_speed'][0]);
            throw $e;
        }
    }

    /** @test */
    public function scene_speed_rejected_when_stored_scene_is_static()
    {
        // Bulb has Warm white (11) stored. Request sends scene_speed without
        // a new scene_id — effective scene is the stored one, which is static.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'scene_id' => 11,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        try {
            $validator->validate($bulb, [
                'scene_speed' => 150,
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('scene_speed', $errors);
            $this->assertStringContainsString('Warm white', $errors['scene_speed'][0]);
            throw $e;
        }
    }

    /** @test */
    public function scene_speed_rejected_when_no_scene_active()
    {
        // Bulb has no stored scene (scene_id is null) and request carries no
        // scene_id. Speed applies to nothing — should be rejected.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'scene_id' => null,
        ]);
        $validator = $this->makeValidator();

        $this->expectException(ValidationException::class);

        try {
            $validator->validate($bulb, [
                'scene_speed' => 150,
            ]);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('scene_speed', $errors);
            // Error should indicate no scene is active.
            $this->assertStringContainsString('scene', $errors['scene_speed'][0]);
            throw $e;
        }
    }

    /** @test */
    public function scene_speed_accepted_when_effective_scene_is_animated()
    {
        // Ocean (1) is an animated scene. Speed should be accepted.
        $bulb = $this->mockBulb([
            'capability_class' => CapabilityClass::FULL_COLOUR,
        ]);
        $validator = $this->makeValidator();

        try {
            $validator->validate($bulb, [
                'scene_id' => 1,
                'scene_speed' => 150,
            ]);
            $this->assertTrue(true, 'scene_speed must be accepted for an animated scene.');
        } catch (ValidationException $e) {
            $this->fail('Unexpected ValidationException: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // filterForRoom: scene_speed filtered for static or absent effective scene
    // ------------------------------------------------------------------

    /** @test */
    public function filterForRoom_records_skip_for_scene_speed_on_static_scene()
    {
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-static-scene-speed',
            'capability_class' => CapabilityClass::FULL_COLOUR,
        ]);

        $validated = [
            'scene_id' => 11,
            'scene_speed' => 150,
            'dimming' => 75,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        // scene_speed should be dropped from applicable values.
        $this->assertArrayNotHasKey('scene_speed', $applicable);
        // And recorded as a skip.
        $speedSkips = array_filter($skips, fn ($s) => $s['field'] === 'scene_speed');
        $this->assertCount(1, $speedSkips);
        $this->assertSame('bulb-static-scene-speed', $speedSkips[0]['bulb_id']);
        // Skip reason should name the scene as not animated.
        $this->assertStringContainsString('Warm white', $speedSkips[0]['reason']);
        $this->assertStringContainsString('animated', $speedSkips[0]['reason']);
    }

    /** @test */
    public function filterForRoom_records_skip_for_scene_speed_when_no_scene()
    {
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-no-scene-speed',
            'capability_class' => CapabilityClass::FULL_COLOUR,
            'scene_id' => null,
        ]);

        $validated = [
            'scene_speed' => 150,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        $this->assertArrayNotHasKey('scene_speed', $applicable);
        $speedSkips = array_filter($skips, fn ($s) => $s['field'] === 'scene_speed');
        $this->assertCount(1, $speedSkips);
        $this->assertSame('bulb-no-scene-speed', $speedSkips[0]['bulb_id']);
    }

    /** @test */
    public function filterForRoom_passes_scene_speed_on_animated_scene()
    {
        $validator = $this->makeValidator();

        $bulb = $this->mockBulb([
            'id' => 'bulb-animated-scene-speed',
            'capability_class' => CapabilityClass::FULL_COLOUR,
        ]);

        $validated = [
            'scene_id' => 1,
            'scene_speed' => 140,
            'dimming' => 80,
        ];

        $skips = [];
        $applicable = $validator->filterForRoom($bulb, $validated, $skips);

        $this->assertArrayHasKey('scene_speed', $applicable);
        $this->assertSame(140, $applicable['scene_speed']);
        $speedSkips = array_filter($skips, fn ($s) => $s['field'] === 'scene_speed');
        $this->assertCount(0, $speedSkips);
    }
}
