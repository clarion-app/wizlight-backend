<?php

namespace ClarionApp\WizlightBackend\Capability;

use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Mode\ActiveMode;
use ClarionApp\WizlightBackend\Scenes\SceneCatalogue;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Validates command payloads against a bulb's stored capability record.
 *
 * Two modes:
 *  - `validate()` — strict; throws ValidationException on the first violation.
 *  - `filterForRoom()` — permissive; returns the applicable subset and
 *    records drops in $skips (for room fan-out).
 */
class DeviceCapabilityValidator
{
    /**
     * Fields that carry colour information.
     */
    private const COLOUR_FIELDS = ['red', 'green', 'blue'];

    /**
     * Validate the payload against the bulb's capability class.
     *
     * @param  Bulb  $bulb  Bulb with capability columns populated.
     * @param  array  $validated  Request payload (already LARAVEL-validated).
     * @return void
     * @throws ValidationException  Field-keyed errors on violation.
     */
    public function validate(Bulb $bulb, array $validated): void
    {
        $errors = [];
        $cc = $bulb->getAttribute('capability_class');

        // --- Colour (FR-008) ---
        // Red/green/blue are one conceptual "set colour" command. A device that
        // can't do colour rejects all three fields present in the request as
        // soon as any one of them is non-zero (all-zero is a no-op, never a
        // colour request, and is always allowed regardless of class).
        if ($cc !== CapabilityClass::FULL_COLOUR) {
            $colourFieldsPresent = array_values(array_intersect(self::COLOUR_FIELDS, array_keys($validated)));

            if (!empty($colourFieldsPresent)) {
                $anyNonZero = false;
                foreach ($colourFieldsPresent as $field) {
                    if ($validated[$field] !== 0) {
                        $anyNonZero = true;
                        break;
                    }
                }

                if ($anyNonZero) {
                    foreach ($colourFieldsPresent as $field) {
                        $errors[$field] = [
                            sprintf(
                                'Colour control not supported by %s device',
                                $cc ?: 'unprobed'
                            ),
                        ];
                    }
                }
            }
        }

        // --- Temperature (FR-009) ---
        // Allowlist: only tunable_white and full_colour may accept temperature.
        // NULL, dim_only, or any corrupted value → reject.
        if ($cc !== CapabilityClass::TUNABLE_WHITE && $cc !== CapabilityClass::FULL_COLOUR) {
            if (isset($validated['temperature'])) {
                $errors['temperature'] = [
                    sprintf(
                        'Temperature control not supported by %s device',
                        $cc ?: 'unprobed'
                    ),
                ];
            }
        } else {
            // In-range check (inclusive boundaries, FR-013).
            if (isset($validated['temperature'])) {
                $warmthMin = $bulb->getAttribute('warmth_min_kelvin');
                $warmthMax = $bulb->getAttribute('warmth_max_kelvin');

                if ($warmthMin !== null && $validated['temperature'] < $warmthMin) {
                    $errors['temperature'] = [
                        sprintf(
                            'Temperature %d K is below device minimum %d K',
                            $validated['temperature'],
                            $warmthMin
                        ),
                    ];
                } elseif ($warmthMax !== null && $validated['temperature'] > $warmthMax) {
                    $errors['temperature'] = [
                        sprintf(
                            'Temperature %d K exceeds device maximum %d K',
                            $validated['temperature'],
                            $warmthMax
                        ),
                    ];
                }
            }
        }

        // --- Dimming (FR-010) ---
        // dimming === 0 is always allowed (means "turn off").
        // dimming > 0 must be >= min_brightness_pct.
        if (isset($validated['dimming']) && $validated['dimming'] > 0) {
            $minBrightness = $bulb->getAttribute('min_brightness_pct');
            if ($minBrightness === null) {
                $minBrightness = (int) config('wizlight.capability.default_min_brightness_pct', 1);
            }

            if ($validated['dimming'] < $minBrightness) {
                $errors['dimming'] = [
                    sprintf(
                        'Dimming value %d is below device minimum %d%%',
                        $validated['dimming'],
                        $minBrightness
                    ),
                ];
            }
        }

        // --- Active mode (T021) ---
        // Reject active_mode values incompatible with the device's capability class.
        if (isset($validated['active_mode'])) {
            $mode = $validated['active_mode'];
            $rejected = false;
            $reason = '';

            switch ($mode) {
                case ActiveMode::RGB:
                    if ($cc !== CapabilityClass::FULL_COLOUR) {
                        $rejected = true;
                        $reason = sprintf('RGB mode not supported by %s device', $cc ?: 'unprobed');
                    }
                    break;

                case ActiveMode::WHITE_CHANNELS:
                    // White channels require dual-head full_colour devices.
                    if ($cc !== CapabilityClass::FULL_COLOUR) {
                        $rejected = true;
                        $reason = sprintf('White channels mode not supported by %s device', $cc ?: 'unprobed');
                    }
                    break;

                case ActiveMode::WARMTH:
                    if ($cc !== CapabilityClass::TUNABLE_WHITE && $cc !== CapabilityClass::FULL_COLOUR) {
                        $rejected = true;
                        $reason = sprintf('Warmth mode not supported by %s device', $cc ?: 'unprobed');
                    }
                    break;

                case ActiveMode::SCENE:
                    $availableScenes = SceneCatalogue::forCapabilityClass($cc);
                    if (empty($availableScenes)) {
                        $rejected = true;
                        $reason = sprintf('Scene mode not supported by %s device', $cc ?: 'unprobed');
                    }
                    break;
            }

            if ($rejected) {
                $errors['active_mode'] = [$reason];
            }
        }

        // --- Scene ID (US1, T031) ---
        // Reject scene_id when the scene is not supported by this device's class,
        // or when the scene id is not in the catalogue at all.
        if (isset($validated['scene_id'])) {
            $sceneId = $validated['scene_id'];
            $scene = SceneCatalogue::find($sceneId);

            if ($scene === null) {
                // Unknown scene ID — not in the catalogue.
                $errors['scene_id'] = [
                    sprintf('Scene %d is not in the catalogue', $sceneId),
                ];
            } elseif (!in_array($cc, $scene->classes, true)) {
                // Scene exists but not for this capability class.
                $errors['scene_id'] = [
                    sprintf(
                        "Scene '%s' (%d) is not supported by %s device",
                        $scene->name,
                        $sceneId,
                        $cc ?: 'unprobed'
                    ),
                ];
            }
        }

        // --- Scene Speed (US2, T050) ---
        // Reject scene_speed when the effective scene is static or absent.
        // Effective scene = request's scene_id if present, else bulb's stored scene_id.
        if (isset($validated['scene_speed'])) {
            $effectiveSceneId = isset($validated['scene_id'])
                ? $validated['scene_id']
                : $bulb->getAttribute('scene_id');

            if ($effectiveSceneId === null) {
                $errors['scene_speed'] = [
                    'No scene is active; scene_speed applies only to an animated scene',
                ];
            } else {
                $scene = SceneCatalogue::find($effectiveSceneId);
                if ($scene === null || !$scene->animated) {
                    $sceneName = $scene ? $scene->name : "Scene {$effectiveSceneId}";
                    $errors['scene_speed'] = [
                        sprintf(
                            "Scene '%s' (%d) is static (not animated) and takes no speed",
                            $sceneName,
                            $effectiveSceneId
                        ),
                    ];
                }
            }
        }

        if (!empty($errors)) {
            self::throwValidationException($errors);
        }
    }

    /**
     * Build and throw a field-keyed ValidationException without touching the
     * container.
     *
     * `ValidationException::withMessages()` resolves the `validator` service
     * out of the container via the `Validator` facade — fine in a booted
     * Laravel app, but this class must also work when unit-tested in
     * isolation (a plain `Illuminate\Validation\Validator` needs only a
     * `Translator`, not the container, as long as no rules are being run).
     *
     * @param  array<string, array<int, string>>  $errors
     * @throws ValidationException
     */
    private static function throwValidationException(array $errors): never
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $validator = new Validator($translator, [], []);

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $validator->errors()->add($field, $message);
            }
        }

        throw new ValidationException($validator);
    }

    /**
     * Filter a payload to only the fields applicable to this bulb.
     *
     * Used for room fan-out (FR-016). Never throws; drops are recorded
     * in the $skips reference.
     *
     * @param  Bulb  $bulb  Bulb with capability columns populated.
     * @param  array  $validated  Full request payload.
     * @param  array  $skips  Populated with drop records.
     * @return array  Subset of $validated applicable to this bulb.
     */
    public function filterForRoom(Bulb $bulb, array $validated, array &$skips): array
    {
        $applicable = [];
        $cc = $bulb->getAttribute('capability_class');
        $bulbId = $bulb->getAttribute('id');

        // Fields that always pass through (not capability-gated).
        $alwaysPass = ['state', 'name', 'room_id'];

        foreach ($alwaysPass as $field) {
            if (array_key_exists($field, $validated)) {
                $applicable[$field] = $validated[$field];
            }
        }

        // Colour: only full_colour accepts a real (non-zero) colour request.
        // Red/green/blue are one conceptual command — a non-full_colour
        // device drops all three present fields together as soon as any one
        // of them is non-zero; an all-zero colour request is harmless and
        // passes through untouched.
        $colourFieldsPresent = array_values(array_intersect(self::COLOUR_FIELDS, array_keys($validated)));

        if (!empty($colourFieldsPresent)) {
            if ($cc === CapabilityClass::FULL_COLOUR) {
                foreach ($colourFieldsPresent as $field) {
                    $applicable[$field] = $validated[$field];
                }
            } else {
                $anyNonZero = false;
                foreach ($colourFieldsPresent as $field) {
                    if ($validated[$field] !== 0) {
                        $anyNonZero = true;
                        break;
                    }
                }

                if ($anyNonZero) {
                    foreach ($colourFieldsPresent as $field) {
                        $skips[] = [
                            'bulb_id' => $bulbId,
                            'field' => $field,
                            'reason' => sprintf(
                                'Colour control not supported by %s device',
                                $cc ?: 'unprobed'
                            ),
                        ];
                    }
                } else {
                    foreach ($colourFieldsPresent as $field) {
                        $applicable[$field] = $validated[$field];
                    }
                }
            }
        }

        // Temperature: allowlist check.
        if (array_key_exists('temperature', $validated)) {
            if ($cc === CapabilityClass::TUNABLE_WHITE || $cc === CapabilityClass::FULL_COLOUR) {
                $warmthMin = $bulb->getAttribute('warmth_min_kelvin');
                $warmthMax = $bulb->getAttribute('warmth_max_kelvin');

                $inRange = true;
                if ($warmthMin !== null && $validated['temperature'] < $warmthMin) {
                    $inRange = false;
                }
                if ($warmthMax !== null && $validated['temperature'] > $warmthMax) {
                    $inRange = false;
                }

                if ($inRange) {
                    $applicable['temperature'] = $validated['temperature'];
                } else {
                    $reason = $warmthMin !== null
                        ? sprintf('Temperature %d K outside device range %d-%d K', $validated['temperature'], $warmthMin, $warmthMax ?: 0)
                        : sprintf('Temperature control range not known for %s device', $cc ?: 'unprobed');
                    $skips[] = [
                        'bulb_id' => $bulbId,
                        'field' => 'temperature',
                        'reason' => $reason,
                    ];
                }
            } else {
                $skips[] = [
                    'bulb_id' => $bulbId,
                    'field' => 'temperature',
                    'reason' => sprintf(
                        'Temperature control not supported by %s device',
                        $cc ?: 'unprobed'
                    ),
                ];
            }
        }

        // Dimming: always passes (dimming === 0 is always valid).
        if (array_key_exists('dimming', $validated)) {
            $applicable['dimming'] = $validated['dimming'];
        }

        // Active mode: capability-gated (T021)
        if (array_key_exists('active_mode', $validated)) {
            $mode = $validated['active_mode'];
            $compatible = true;

            switch ($mode) {
                case ActiveMode::RGB:
                    if ($cc !== CapabilityClass::FULL_COLOUR) {
                        $compatible = false;
                    }
                    break;

                case ActiveMode::WHITE_CHANNELS:
                    if ($cc !== CapabilityClass::FULL_COLOUR) {
                        $compatible = false;
                    }
                    break;

                case ActiveMode::WARMTH:
                    if ($cc !== CapabilityClass::TUNABLE_WHITE && $cc !== CapabilityClass::FULL_COLOUR) {
                        $compatible = false;
                    }
                    break;

                case ActiveMode::SCENE:
                    $availableScenes = SceneCatalogue::forCapabilityClass($cc);
                    if (empty($availableScenes)) {
                        $compatible = false;
                    }
                    break;
            }

            if ($compatible) {
                $applicable['active_mode'] = $validated['active_mode'];
            } else {
                $skips[] = [
                    'bulb_id' => $bulbId,
                    'field' => 'active_mode',
                    'reason' => sprintf(
                        'Active mode %s not supported by %s device',
                        $mode,
                        $cc ?: 'unprobed'
                    ),
                ];
            }
        }

        // Scene ID: capability-gated (US1, T031)
        if (array_key_exists('scene_id', $validated)) {
            $sceneId = $validated['scene_id'];
            $scene = SceneCatalogue::find($sceneId);

            if ($scene === null) {
                // Unknown scene ID — not in the catalogue.
                $skips[] = [
                    'bulb_id' => $bulbId,
                    'field' => 'scene_id',
                    'reason' => sprintf('Scene %d is not in the catalogue', $sceneId),
                ];
            } elseif (!in_array($cc, $scene->classes, true)) {
                $skips[] = [
                    'bulb_id' => $bulbId,
                    'field' => 'scene_id',
                    'reason' => sprintf(
                        "Scene '%s' (%d) is not supported by %s device",
                        $scene->name,
                        $sceneId,
                        $cc ?: 'unprobed'
                    ),
                ];
            } else {
                $applicable['scene_id'] = $validated['scene_id'];
            }
        }

        // Scene Speed: capability-gated (US2, T050)
        // Only pass through when the effective scene is animated.
        if (array_key_exists('scene_speed', $validated)) {
            $effectiveSceneId = array_key_exists('scene_id', $validated)
                ? $validated['scene_id']
                : $bulb->getAttribute('scene_id');

            if ($effectiveSceneId === null) {
                $skips[] = [
                    'bulb_id' => $bulbId,
                    'field' => 'scene_speed',
                    'reason' => 'No scene is active; speed applies only to an animated scene',
                ];
            } else {
                $scene = SceneCatalogue::find($effectiveSceneId);
                if ($scene === null || !$scene->animated) {
                    $sceneName = $scene ? $scene->name : "Scene {$effectiveSceneId}";
                    $skips[] = [
                        'bulb_id' => $bulbId,
                        'field' => 'scene_speed',
                        'reason' => sprintf(
                            "Scene '%s' (%d) is not animated and takes no speed",
                            $sceneName,
                            $effectiveSceneId
                        ),
                    ];
                } else {
                    $applicable['scene_speed'] = $validated['scene_speed'];
                }
            }
        }

        return $applicable;
    }
}
