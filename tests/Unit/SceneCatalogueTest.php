<?php

namespace ClarionApp\WizlightBackend\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\WizlightBackend\Scenes\SceneCatalogue;
use ClarionApp\WizlightBackend\Scenes\SceneDefinition;
use ClarionApp\WizlightBackend\Capability\CapabilityClass;

/**
 * Tests against the 37-entry catalogue in contracts/scene-catalogue.md.
 * Counts, animated/static split, and FR-014 exclusions all verified here.
 */
class SceneCatalogueTest extends TestCase
{
    // ------------------------------------------------------------------
    // Per-class counts (matching spec US1 acceptance scenarios)
    // ------------------------------------------------------------------

    /** @test */
    public function full_colour_has_36_scenes()
    {
        $scenes = SceneCatalogue::forCapabilityClass(CapabilityClass::FULL_COLOUR);
        $this->assertCount(36, $scenes, 'full_colour devices should have 36 scenes (IDs 1–36)');
    }

    /** @test */
    public function tunable_white_has_17_scenes()
    {
        $scenes = SceneCatalogue::forCapabilityClass(CapabilityClass::TUNABLE_WHITE);
        $this->assertCount(17, $scenes, 'tunable_white devices should have 17 scenes');
    }

    /** @test */
    public function dim_only_has_8_scenes()
    {
        $scenes = SceneCatalogue::forCapabilityClass(CapabilityClass::DIM_ONLY);
        $this->assertCount(8, $scenes, 'dim_only devices should have 8 scenes');
    }

    // ------------------------------------------------------------------
    // Null class reads as dim_only (066 convention)
    // ------------------------------------------------------------------

    /** @test */
    public function null_capability_class_reads_as_dim_only()
    {
        $scenes = SceneCatalogue::forCapabilityClass(null);
        $this->assertCount(8, $scenes, 'null class should read as dim_only (8 scenes)');
    }

    // ------------------------------------------------------------------
    // find() behaviour
    // ------------------------------------------------------------------

    /** @test */
    public function find_returns_scene_definition_for_known_id()
    {
        $scene = SceneCatalogue::find(1);
        $this->assertInstanceOf(SceneDefinition::class, $scene);
        $this->assertSame(1, $scene->id);
        $this->assertSame('Ocean', $scene->name);
        $this->assertTrue($scene->animated);
    }

    /** @test */
    public function find_returns_null_for_unknown_id()
    {
        $scene = SceneCatalogue::find(57);
        $this->assertNull($scene, 'find() should return null (not throw) for an unknown scene ID');
    }

    // ------------------------------------------------------------------
    // FR-014: Custom Modes 256–265 and Rhythm 1000 absent
    // ------------------------------------------------------------------

    /** @test */
    public function custom_modes_256_to_265_are_absent()
    {
        foreach (range(256, 265) as $id) {
            $this->assertNull(
                SceneCatalogue::find($id),
                "Custom mode ID {$id} should be absent from the catalogue (FR-014)"
            );
        }
    }

    /** @test */
    public function rhythm_1000_is_absent()
    {
        $this->assertNull(
            SceneCatalogue::find(1000),
            'Rhythm ID 1000 should be absent from the catalogue (FR-014)'
        );
    }

    // ------------------------------------------------------------------
    // Animated / static split (contracts/scene-catalogue.md reconciled table)
    // ------------------------------------------------------------------

    /** @test */
    public function all_scenes_are_classified_animated_or_static()
    {
        $scenes = SceneCatalogue::all();
        $this->assertCount(37, $scenes, 'catalogue should contain exactly 37 entries');

        foreach ($scenes as $scene) {
            $this->assertIsBool($scene->animated, "Scene {$scene->id} should have a boolean animated flag");
        }
    }

    /** @test */
    public function animated_scene_list_matches_contracts()
    {
        // Animated: 1–10, 20, 21, 22, 23, 24, 26, 31, 32, 33, 36
        $animatedIds = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 20, 21, 22, 23, 24, 26, 31, 32, 33, 36];
        foreach ($animatedIds as $id) {
            $scene = SceneCatalogue::find($id);
            $this->assertNotNull($scene, "Scene {$id} should exist");
            $this->assertTrue($scene->animated, "Scene {$id} ({$scene->name}) should be animated");
            $this->assertTrue(SceneCatalogue::isAnimated($id), "isAnimated({$id}) should return true");
        }
    }

    /** @test */
    public function static_scene_list_matches_contracts()
    {
        // Static: 11–19, 25, 27, 28, 29, 30, 34, 35, 40
        $staticIds = [11, 12, 13, 14, 15, 16, 17, 18, 19, 25, 27, 28, 29, 30, 34, 35, 40];
        foreach ($staticIds as $id) {
            $scene = SceneCatalogue::find($id);
            $this->assertNotNull($scene, "Scene {$id} should exist");
            $this->assertFalse($scene->animated, "Scene {$id} ({$scene->name}) should be static");
            $this->assertFalse(SceneCatalogue::isAnimated($id), "isAnimated({$id}) should return false");
        }
    }

    /** @test */
    public function isAnimated_returns_false_for_unknown_id()
    {
        $this->assertFalse(
            SceneCatalogue::isAnimated(999),
            'isAnimated() should return false for an unknown ID (conservative default)'
        );
    }

    /** @test */
    public function four_disputed_scenes_follow_clarification()
    {
        // The four scenes where spec.md Key Entities ranges disagree with
        // the Clarifications session (authoritative per contracts/scene-catalogue.md).
        // 20 Spring  — animated (Key Entities said static)
        $this->assertTrue(SceneCatalogue::find(20)->animated, 'Spring (20) should be animated');
        // 27 Christmas — static (Key Entities said animated)
        $this->assertFalse(SceneCatalogue::find(27)->animated, 'Christmas (27) should be static');
        // 31 Pulse  — animated (Key Entities said static)
        $this->assertTrue(SceneCatalogue::find(31)->animated, 'Pulse (31) should be animated');
        // 35 Alarm  — static (Key Entities said animated)
        $this->assertFalse(SceneCatalogue::find(35)->animated, 'Alarm (35) should be static');
    }

    // ------------------------------------------------------------------
    // SPEED_MIN / SPEED_MAX constants
    // ------------------------------------------------------------------

    /** @test */
    public function speed_min_is_10()
    {
        $this->assertSame(10, SceneCatalogue::SPEED_MIN);
    }

    /** @test */
    public function speed_max_is_200()
    {
        $this->assertSame(200, SceneCatalogue::SPEED_MAX);
    }

    // ------------------------------------------------------------------
    // supports() method
    // ------------------------------------------------------------------

    /** @test */
    public function supports_returns_true_for_matching_class()
    {
        $this->assertTrue(SceneCatalogue::supports(CapabilityClass::FULL_COLOUR, 1));
        $this->assertTrue(SceneCatalogue::supports(CapabilityClass::TUNABLE_WHITE, 40));
        $this->assertTrue(SceneCatalogue::supports(CapabilityClass::DIM_ONLY, 14));
    }

    /** @test */
    public function supports_returns_false_for_non_matching_class()
    {
        // Ocean (1) is full_colour only
        $this->assertFalse(SceneCatalogue::supports(CapabilityClass::TUNABLE_WHITE, 1));
        $this->assertFalse(SceneCatalogue::supports(CapabilityClass::DIM_ONLY, 1));

        // Dim-to-warm (40) is tunable_white only
        $this->assertFalse(SceneCatalogue::supports(CapabilityClass::FULL_COLOUR, 40));
        $this->assertFalse(SceneCatalogue::supports(CapabilityClass::DIM_ONLY, 40));
    }

    /** @test */
    public function supports_returns_false_for_unknown_id()
    {
        $this->assertFalse(SceneCatalogue::supports(CapabilityClass::FULL_COLOUR, 999));
        $this->assertFalse(SceneCatalogue::supports(CapabilityClass::FULL_COLOUR, 256));
    }

    // ------------------------------------------------------------------
    // SceneDefinition value object
    // ------------------------------------------------------------------

    /** @test */
    public function scene_definition_has_correct_shape()
    {
        $scene = SceneCatalogue::find(9);
        $this->assertInstanceOf(SceneDefinition::class, $scene);
        $this->assertSame(9, $scene->id);
        $this->assertSame('Wake-up', $scene->name);
        $this->assertTrue($scene->animated);
        $this->assertIsArray($scene->classes);
        $this->assertContains(CapabilityClass::FULL_COLOUR, $scene->classes);
        $this->assertContains(CapabilityClass::TUNABLE_WHITE, $scene->classes);
        $this->assertContains(CapabilityClass::DIM_ONLY, $scene->classes);
    }

    // ------------------------------------------------------------------
    // all() returns in ID order
    // ------------------------------------------------------------------

    /** @test */
    public function all_returns_entries_in_id_order()
    {
        $scenes = SceneCatalogue::all();
        $ids = array_map(fn ($s) => $s->id, $scenes);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'all() should return scenes in ascending ID order');
    }
}
