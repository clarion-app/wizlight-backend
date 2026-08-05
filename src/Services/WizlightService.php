<?php

namespace ClarionApp\WizlightBackend\Services;

use ClarionApp\WizlightBackend\Capability\DeviceCapabilityValidator;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use ClarionApp\WizlightBackend\Jobs\SendBulbCommand;

class WizlightService
{
    public function updateBulbState(Bulb $bulb, array $validated): Bulb
    {
        $update = false;

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
        $command->params->r = (int) $bulb->red;
        $command->params->g = (int) $bulb->green;
        $command->params->b = (int) $bulb->blue;
        $command->params->dimming = (int) $bulb->dimming;
        $command->params->state = $bulb->state ? 1 : 0;

        if ($bulb->red == 0 && $bulb->green == 0 && $bulb->blue == 0 && $bulb->temperature > 0) {
            $command->params->temp = (int) $bulb->temperature;
        }

        return $command;
    }
}
