<?php

namespace ClarionApp\WizlightBackend\Services;

use ClarionApp\WizlightBackend\Capability\DeviceCapabilityValidator;
use ClarionApp\WizlightBackend\Mode\ActiveMode;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;
use ClarionApp\WizlightBackend\Scenes\SceneCatalogue;

class WizlightService
{
    public function updateBulbState(Bulb $bulb, array $validated): Bulb
    {
        $update = false;

        // --- Mode decision chain (T019) ---
        // 1. Explicit active_mode from request.
        // 2. Inferred from field groups in request vs stored.
        // 3. Stored active_mode (unchanged — no mode change requested).
        $currentMode = null;
        if (array_key_exists('active_mode', $validated)) {
            $currentMode = $validated['active_mode'];
        } else {
            try {
                $inferred = ActiveMode::infer(
                    $validated,
                    [
                        'red' => $bulb->red,
                        'green' => $bulb->green,
                        'blue' => $bulb->blue,
                        'temperature' => $bulb->temperature,
                        'white_warm' => $bulb->white_warm,
                        'white_cool' => $bulb->white_cool,
                        'scene_id' => $bulb->scene_id,
                    ]
                );
                if ($inferred !== null) {
                    $currentMode = $inferred;
                }
            } catch (\RuntimeException) {
                // AmbiguousModeException — caller (controller) should have
                // caught it, but if we reach here, fall through to stored mode.
            }
        }

        // If neither explicit nor inferred, keep the stored mode.
        // Do NOT call resolve() here — it would infer a mode from stored
        // values even when no mode change is requested, causing a spurious
        // write (e.g., stored null → inferred 'rgb' → save dispatched).
        if ($currentMode === null) {
            $currentMode = $bulb->active_mode;
        }

        // Write active_mode only if it actually changed.
        if ($bulb->active_mode !== $currentMode) {
            $bulb->active_mode = $currentMode;
            $update = true;
        }

        // --- Scene fields ---
        if (isset($validated['scene_id']) && $bulb->scene_id != $validated['scene_id']) {
            $bulb->scene_id = $validated['scene_id'];
            $update = true;
        }

        if (isset($validated['scene_speed']) && $bulb->scene_speed != $validated['scene_speed']) {
            $bulb->scene_speed = $validated['scene_speed'];
            $update = true;
        }

        // --- Common fields (unchanged) ---
        if (isset($validated['state'])) {
            $newState = $validated['state'] ? true : false;
            if ($bulb->state != $newState) {
                $bulb->state = $newState;
                $update = true;
            }
        }

        if (isset($validated['red']) && $bulb->red != $validated['red']) {
            $bulb->red = $validated['red'];
            $update = true;
        }

        if (isset($validated['green']) && $bulb->green != $validated['green']) {
            $bulb->green = $validated['green'];
            $update = true;
        }

        if (isset($validated['blue']) && $bulb->blue != $validated['blue']) {
            $bulb->blue = $validated['blue'];
            $update = true;
        }

        if (isset($validated['temperature']) && $bulb->temperature != $validated['temperature']) {
            $bulb->temperature = $validated['temperature'];
            $update = true;
        }

        if (isset($validated['dimming']) && $bulb->dimming != $validated['dimming']) {
            $bulb->dimming = $validated['dimming'];
            $update = true;
        }

        // --- White channel fields ---
        if (isset($validated['white_warm']) && $bulb->white_warm != $validated['white_warm']) {
            $bulb->white_warm = $validated['white_warm'];
            $update = true;
        }

        if (isset($validated['white_cool']) && $bulb->white_cool != $validated['white_cool']) {
            $bulb->white_cool = $validated['white_cool'];
            $update = true;
        }

        // --- Head ratio field ---
        if (isset($validated['head_ratio']) && $bulb->head_ratio != $validated['head_ratio']) {
            $bulb->head_ratio = $validated['head_ratio'];
            $update = true;
        }

        // --- Non-mode fields ---
        if (isset($validated['name']) && $bulb->name != $validated['name']) {
            $bulb->name = $validated['name'];
            $update = true;
        }

        if (array_key_exists('room_id', $validated) && $bulb->room_id != $validated['room_id']) {
            $bulb->room_id = $validated['room_id'];
            $update = true;
        }

        if (!$update) {
            return $bulb;
        }

        $bulb->save();

        if (config('clarion.node_id') == $bulb->local_node_id) {
            $this->sendCommandNow($bulb);
        }

        event(new BulbStatusEvent($bulb));

        return $bulb;
    }

    /**
     * Contact the device in the request rather than through the queue.
     *
     * The command is a fire-and-forget UDP datagram — nothing is read back —
     * so the send itself costs microseconds. Deferring it bought nothing and
     * cost a great deal: every command landed on the same queue as the
     * periodic discovery run and the per-light status sweep, and a single
     * worker serves them strictly in order, so a button press waited behind
     * up to a full sweep before it reached the light.
     *
     * Ordering (the per-bulb lock) and the per-device pace still apply, since
     * both live in the job's handle(). A send that throws still broadcasts the
     * failure event the queued path raised through failed().
     */
    private function sendCommandNow(Bulb $bulb): void
    {
        $job = new SendBulbCommand(
            $bulb->ip,
            $this->buildCommand($bulb),
            (string) $bulb->id
        );

        try {
            dispatch_sync($job);
        } catch (\Throwable $e) {
            $job->failed($e);
        }
    }

    /**
     * Update room aggregate row and fan out to member bulbs.
     *
     * @return array{room: Room, capability_skips: array[]}
     */
    public function updateRoomState(Room $room, array $validated): array
    {
        $update = false;

        if (isset($validated['name']) && $room->name != $validated['name']) {
            $room->name = $validated['name'];
            $update = true;
        }

        if (isset($validated['state'])) {
            $newState = $validated['state'] ? true : false;
            if ($room->state != $newState) {
                $room->state = $newState;
                $update = true;
            }
        }

        if (isset($validated['red']) && $room->red != $validated['red']) {
            $room->red = $validated['red'];
            $update = true;
        }

        if (isset($validated['green']) && $room->green != $validated['green']) {
            $room->green = $validated['green'];
            $update = true;
        }

        if (isset($validated['blue']) && $room->blue != $validated['blue']) {
            $room->blue = $validated['blue'];
            $update = true;
        }

        if (isset($validated['temperature']) && $room->temperature != $validated['temperature']) {
            $room->temperature = $validated['temperature'];
            $update = true;
        }

        if (isset($validated['dimming']) && $room->dimming != $validated['dimming']) {
            $room->dimming = $validated['dimming'];
            $update = true;
        }

        // Scene fields on room aggregate (T020)
        if (isset($validated['active_mode']) && $room->active_mode != $validated['active_mode']) {
            $room->active_mode = $validated['active_mode'];
            $update = true;
        }

        if (isset($validated['scene_id']) && $room->scene_id != $validated['scene_id']) {
            $room->scene_id = $validated['scene_id'];
            $update = true;
        }

        if (isset($validated['scene_speed']) && $room->scene_speed != $validated['scene_speed']) {
            $room->scene_speed = $validated['scene_speed'];
            $update = true;
        }

        if ($update) {
            $room->save();
        }

        if ($room->bulbs->isEmpty()) {
            return ['room' => $room, 'capability_skips' => []];
        }

        $bulbUpdate = false;
        $skips = [];
        $validator = new DeviceCapabilityValidator();

        foreach ($room->bulbs as $bulb) {
            // Filter validated payload for this bulb's capability class.
            $applicable = $validator->filterForRoom($bulb, $validated, $skips);

            // Skip entirely if nothing applicable remains.
            if (empty($applicable)) {
                continue;
            }

            $bulbChanged = false;

            if (isset($applicable['state']) && $bulb->state != $applicable['state']) {
                $bulb->state = $applicable['state'];
                $bulbChanged = true;
            }

            if (isset($applicable['red']) && $bulb->red != $applicable['red']) {
                $bulb->red = $applicable['red'];
                $bulbChanged = true;
            }

            if (isset($applicable['green']) && $bulb->green != $applicable['green']) {
                $bulb->green = $applicable['green'];
                $bulbChanged = true;
            }

            if (isset($applicable['blue']) && $bulb->blue != $applicable['blue']) {
                $bulb->blue = $applicable['blue'];
                $bulbChanged = true;
            }

            if (isset($applicable['temperature']) && $bulb->temperature != $applicable['temperature']) {
                $bulb->temperature = $applicable['temperature'];
                $bulbChanged = true;
            }

            if (isset($applicable['dimming']) && $bulb->dimming != $applicable['dimming']) {
                $bulb->dimming = $applicable['dimming'];
                $bulbChanged = true;
            }

            // Scene fields per member bulb (T020)
            if (isset($applicable['scene_id']) && $bulb->scene_id != $applicable['scene_id']) {
                $bulb->scene_id = $applicable['scene_id'];
                $bulbChanged = true;
            }

            if (isset($applicable['scene_speed']) && $bulb->scene_speed != $applicable['scene_speed']) {
                $bulb->scene_speed = $applicable['scene_speed'];
                $bulbChanged = true;
            }

            if ($bulbChanged) {
                $bulb->save();
                $bulbUpdate = true;

                if (config('clarion.node_id') == $bulb->local_node_id) {
                    $this->sendCommandNow($bulb);
                }

                event(new BulbStatusEvent($bulb));
            }
        }

        return ['room' => $room, 'capability_skips' => $skips];
    }

    public function buildCommand(object $bulb): object
    {
        $command = new \stdClass();
        $command->method = 'setPilot';
        $command->params = new \stdClass();
        $command->params->dimming = (int) ($bulb->dimming ?? 100);
        $command->params->state = (isset($bulb->state) && $bulb->state) ? 1 : 0;

        // Resolve the active mode (T018)
        $mode = ActiveMode::resolve(
            $bulb->active_mode ?? null,
            [
                'red' => $bulb->red ?? 0,
                'green' => $bulb->green ?? 0,
                'blue' => $bulb->blue ?? 0,
                'temperature' => $bulb->temperature ?? 0,
            ]
        );

        // Mode-specific fields
        switch ($mode) {
            case ActiveMode::SCENE:
                $command->params->sceneId = (int) ($bulb->scene_id ?? 0);
                // Animated scenes include speed; static scenes do not.
                if (SceneCatalogue::isAnimated((int) ($bulb->scene_id ?? 0))) {
                    $command->params->speed = (int) ($bulb->scene_speed ?? config('wizlight.scene.default_speed', 100));
                }
                break;

            case ActiveMode::RGB:
                $command->params->r = (int) ($bulb->red ?? 0);
                $command->params->g = (int) ($bulb->green ?? 0);
                $command->params->b = (int) ($bulb->blue ?? 0);
                break;

            case ActiveMode::WARMTH:
                $command->params->temp = (int) ($bulb->temperature ?? 0);
                break;

            case ActiveMode::WHITE_CHANNELS:
                $command->params->w = (int) ($bulb->white_warm ?? 0);
                $command->params->c = (int) ($bulb->white_cool ?? 0);
                break;
        }

        // Dual-head ratio. Orthogonal to the mode above: it rides along with
        // whichever mode's own parameters the command carries.
        //
        // Gated on the *stored* capability fact, never re-derived from the
        // module name here — the derivation belongs to discovery, and running
        // it on the command path would mean a corrected flag never took effect
        // until the device was re-probed. NULL ("never probed") reads as
        // single-head, the same conservative fallback a NULL capability class
        // gets.
        $isDualHead = ($bulb->dual_head ?? null) === true;
        if ($isDualHead && isset($bulb->head_ratio) && $bulb->head_ratio !== null) {
            $command->params->ratio = (int) $bulb->head_ratio;
        }

        return $command;
    }
}
