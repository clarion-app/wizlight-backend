<?php

namespace ClarionApp\WizlightBackend\Mode;

/**
 * Mode constants and resolution logic.
 *
 * Four modes, each owning a set of fields. The stored active_mode column
 * disambiguates; a NULL value (legacy row) falls back to the exact condition
 * the old buildCommand() used.
 */
final class ActiveMode
{
    public const RGB            = 'rgb';
    public const WARMTH         = 'warmth';
    public const WHITE_CHANNELS = 'white_channels';
    public const SCENE          = 'scene';

    /**
     * Mode-owned field groups. Anything not listed here is orthogonal.
     */
    public const FIELD_GROUPS = [
        self::RGB            => ['red', 'green', 'blue'],
        self::WARMTH         => ['temperature'],
        self::WHITE_CHANNELS => ['white_warm', 'white_cool'],
        self::SCENE          => ['scene_id', 'scene_speed'],
    ];

    private function __construct() {}

    /**
     * Resolve the effective mode from the stored column, or via legacy
     * inference when the column is NULL.
     */
    public static function resolve(?string $stored, array $values): string
    {
        if ($stored !== null) {
            return $stored;
        }

        // Legacy inference — verbatim transcription of the old buildCommand()
        // condition. A NULL row produces byte-identical setPilot parameters
        // before and after this feature.
        if ($values['red'] == 0 && $values['green'] == 0 && $values['blue'] == 0 && $values['temperature'] > 0) {
            return self::WARMTH;
        }

        return self::RGB;
    }

    /**
     * Which single mode does this request change? Null when none.
     *
     * Compares requested values against stored values (diff-based inference).
     * Presence alone carries no intent — the frontend spreads the whole bulb
     * object, so only a difference signals intent.
     *
     * @throws AmbiguousModeException when more than one group changed.
     */
    public static function infer(array $requested, array $stored): ?string
    {
        $changedGroups = [];

        foreach (self::FIELD_GROUPS as $mode => $fields) {
            foreach ($fields as $field) {
                if (!array_key_exists($field, $requested)) {
                    continue;
                }
                $storedValue = $stored[$field] ?? null;
                if ($requested[$field] != $storedValue) {
                    $changedGroups[] = $mode;
                    break;
                }
            }
        }

        if (count($changedGroups) === 0) {
            return null;
        }

        if (count($changedGroups) > 1) {
            throw new AmbiguousModeException($changedGroups);
        }

        return $changedGroups[0];
    }

    /**
     * Derive the mode a getPilot payload reports, or null when it reports none.
     *
     * Precedence (total and fixed):
     *   sceneId > 0          → SCENE
     *   c or w non-zero      → WHITE_CHANNELS
     *   temp > 0             → WARMTH
     *   r/g/b present        → RGB
     *   otherwise            → null
     */
    public static function fromPilot(array $payload): ?string
    {
        // sceneId present and > 0
        if (isset($payload['sceneId']) && $payload['sceneId'] > 0) {
            return self::SCENE;
        }

        // c or w present and non-zero
        if ((isset($payload['c']) && $payload['c'] !== 0)
            || (isset($payload['w']) && $payload['w'] !== 0)
        ) {
            return self::WHITE_CHANNELS;
        }

        // temp present and > 0
        if (isset($payload['temp']) && $payload['temp'] > 0) {
            return self::WARMTH;
        }

        // r/g/b present
        if (isset($payload['r']) || isset($payload['g']) || isset($payload['b'])) {
            return self::RGB;
        }

        return null;
    }
}
