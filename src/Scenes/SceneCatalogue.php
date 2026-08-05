<?php

namespace ClarionApp\WizlightBackend\Scenes;

use ClarionApp\WizlightBackend\Capability\CapabilityClass;

/**
 * Fixed 37-entry scene catalogue keyed by manufacturer ID.
 *
 * Availability lives on each row (classes array), so filtering is a simple
 * array_filter — there is no per-class branch anywhere in the codebase.
 */
final class SceneCatalogue
{
    public const SPEED_MIN = 10;
    public const SPEED_MAX = 200;

    /**
     * 37 entries: manufacturer IDs 1–36 and 40.
     * IDs are assigned by the manufacturer and are never renumbered.
     * Custom Modes 256–265 and Rhythm 1000 are out of scope (FR-014).
     *
     * @internal
     */
    private const SCENES = [
        // ID => [name, animated, classes]
        1  => ['Ocean',          true,  [CapabilityClass::FULL_COLOUR]],
        2  => ['Romance',        true,  [CapabilityClass::FULL_COLOUR]],
        3  => ['Sunset',         true,  [CapabilityClass::FULL_COLOUR]],
        4  => ['Party',          true,  [CapabilityClass::FULL_COLOUR]],
        5  => ['Fireplace',      true,  [CapabilityClass::FULL_COLOUR]],
        6  => ['Cozy',           true,  [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        7  => ['Forest',         true,  [CapabilityClass::FULL_COLOUR]],
        8  => ['Pastel colors',  true,  [CapabilityClass::FULL_COLOUR]],
        9  => ['Wake-up',        true,  [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE, CapabilityClass::DIM_ONLY]],
        10 => ['Bedtime',        true,  [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE, CapabilityClass::DIM_ONLY]],
        11 => ['Warm white',     false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        12 => ['Daylight',       false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        13 => ['Cool white',     false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        14 => ['Night light',    false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE, CapabilityClass::DIM_ONLY]],
        15 => ['Focus',          false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        16 => ['Relax',          false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        17 => ['True colors',    false, [CapabilityClass::FULL_COLOUR]],
        18 => ['TV time',        false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        19 => ['Plantgrowth',    false, [CapabilityClass::FULL_COLOUR]],
        20 => ['Spring',         true,  [CapabilityClass::FULL_COLOUR]],
        21 => ['Summer',         true,  [CapabilityClass::FULL_COLOUR]],
        22 => ['Fall',           true,  [CapabilityClass::FULL_COLOUR]],
        23 => ['Deep dive',      true,  [CapabilityClass::FULL_COLOUR]],
        24 => ['Jungle',         true,  [CapabilityClass::FULL_COLOUR]],
        25 => ['Mojito',         false, [CapabilityClass::FULL_COLOUR]],
        26 => ['Club',           true,  [CapabilityClass::FULL_COLOUR]],
        27 => ['Christmas',      false, [CapabilityClass::FULL_COLOUR]],
        28 => ['Halloween',      false, [CapabilityClass::FULL_COLOUR]],
        29 => ['Candlelight',    false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE, CapabilityClass::DIM_ONLY]],
        30 => ['Golden white',   false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        31 => ['Pulse',          true,  [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE, CapabilityClass::DIM_ONLY]],
        32 => ['Steampunk',      true,  [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE, CapabilityClass::DIM_ONLY]],
        33 => ['Diwali',         true,  [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE]],
        34 => ['White',          false, [CapabilityClass::FULL_COLOUR, CapabilityClass::DIM_ONLY]],
        35 => ['Alarm',          false, [CapabilityClass::FULL_COLOUR, CapabilityClass::TUNABLE_WHITE, CapabilityClass::DIM_ONLY]],
        36 => ['Snowy sky',      true,  [CapabilityClass::FULL_COLOUR]],
        40 => ['Dim-to-warm',    false, [CapabilityClass::TUNABLE_WHITE]],
    ];

    /**
     * All scenes in ascending ID order (zero-indexed array).
     *
     * @return SceneDefinition[]
     */
    public static function all(): array
    {
        return array_values(self::build());
    }

    /**
     * Find a scene by manufacturer ID. Returns null for unknown IDs.
     */
    public static function find(int $id): ?SceneDefinition
    {
        return self::build()[$id] ?? null;
    }

    /**
     * Scenes available for the given capability class.
     * A null class is normalised to DIM_ONLY (066 convention).
     *
     * @return SceneDefinition[]
     */
    public static function forCapabilityClass(?string $class): array
    {
        if ($class === null) {
            $class = CapabilityClass::DIM_ONLY;
        }

        $scenes = self::build();
        return array_values(array_filter($scenes, function (SceneDefinition $s) use ($class) {
            return in_array($class, $s->classes, true);
        }));
    }

    /**
     * Check whether a scene is supported by the given capability class.
     */
    public static function supports(?string $class, int $id): bool
    {
        $scene = self::find($id);
        if ($scene === null) {
            return false;
        }
        if ($class === null) {
            $class = CapabilityClass::DIM_ONLY;
        }
        return in_array($class, $scene->classes, true);
    }

    /**
     * Check whether a scene is animated. Returns false for unknown IDs.
     */
    public static function isAnimated(int $id): bool
    {
        $scene = self::find($id);
        return $scene !== null && $scene->animated;
    }

    /**
     * @return SceneDefinition[]
     */
    private static function build(): array
    {
        $result = [];
        foreach (self::SCENES as $id => $data) {
            $result[$id] = new SceneDefinition(
                id: $id,
                name: $data[0],
                animated: $data[1],
                classes: $data[2],
            );
        }
        return $result;
    }
}
