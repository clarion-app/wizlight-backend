<?php

namespace ClarionApp\WizlightBackend\Capability;

/**
 * Canonical capability-class identifiers.
 *
 * Three tiers, ordered by feature set. Consumers compare against these
 * constants rather than string literals so the classification is stable
 * across refactors.
 */
final class CapabilityClass
{
    /**
     * Dim-only: brightness changes only. No colour, no temperature.
     */
    public const DIM_ONLY = 'dim_only';

    /**
     * Tunable white: brightness + colour temperature (Kelvin range).
     */
    public const TUNABLE_WHITE = 'tunable_white';

    /**
     * Full colour: brightness, temperature, and RGB control.
     */
    public const FULL_COLOUR = 'full_colour';

    private function __construct()
    {
    }
}
