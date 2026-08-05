<?php

namespace ClarionApp\WizlightBackend\Capability;

/**
 * Classify a device's capability tier from its module name and warmth range.
 *
 * Precedence rules:
 *  1. Module-name substring match is primary (RGB → TW → DW → unknown).
 *  2. A provisional dim_only can be upgraded to tunable_white when the warmth
 *     range is non-trivial (min !== max, both non-null).
 *  3. A name-based full_colour is never downgraded.
 *  4. Range alone can never reach full_colour.
 */
class CapabilityClassifier
{
    /**
     * @param string|null $moduleName  e.g. "ESP01_SHRGB_03", "ESP03_SHTW1_01ABI"
     * @param int|null    $warmthMin
     * @param int|null    $warmthMax
     * @return string One of CapabilityClass constants.
     */
    public function classify(?string $moduleName, ?int $warmthMin, ?int $warmthMax): string
    {
        // 1. Name-based classification (primary signal).
        $class = $this->classifyByName($moduleName);

        // 2. One-way upgrade: dim_only → tunable_white when range is usable.
        if ($class === CapabilityClass::DIM_ONLY
            && $warmthMin !== null
            && $warmthMax !== null
            && $warmthMin !== $warmthMax
        ) {
            $class = CapabilityClass::TUNABLE_WHITE;
        }

        return $class;
    }

    /**
     * Substring match in priority order. Unknown / empty / null → dim_only.
     */
    private function classifyByName(?string $moduleName): string
    {
        if ($moduleName === null || $moduleName === '') {
            return CapabilityClass::DIM_ONLY;
        }

        if (str_contains($moduleName, 'RGB')) {
            return CapabilityClass::FULL_COLOUR;
        }

        if (str_contains($moduleName, 'TW')) {
            return CapabilityClass::TUNABLE_WHITE;
        }

        if (str_contains($moduleName, 'DW')) {
            return CapabilityClass::DIM_ONLY;
        }

        return CapabilityClass::DIM_ONLY;
    }
}
