<?php

namespace ClarionApp\WizlightBackend\Services;

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
            $command = $this->buildCommand($bulb);
            SendBulbCommand::dispatch($bulb->ip, $command, (string) $bulb->id);
        }

        event(new BulbStatusEvent($bulb));

        return $bulb;
    }

    public function updateRoomState(Room $room, array $validated): Room
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
            return $room;
        }

        $bulbUpdate = false;
        foreach ($room->bulbs as $bulb) {
            $bulbChanged = false;

            if (isset($validated['state']) && $bulb->state != $validated['state']) {
                $bulb->state = $validated['state'];
                $bulbChanged = true;
            }

            if (isset($validated['red']) && $bulb->red != $validated['red']) {
                $bulb->red = $validated['red'];
                $bulbChanged = true;
            }

            if (isset($validated['green']) && $bulb->green != $validated['green']) {
                $bulb->green = $validated['green'];
                $bulbChanged = true;
            }

            if (isset($validated['blue']) && $bulb->blue != $validated['blue']) {
                $bulb->blue = $validated['blue'];
                $bulbChanged = true;
            }

            if (isset($validated['temperature']) && $bulb->temperature != $validated['temperature']) {
                $bulb->temperature = $validated['temperature'];
                $bulbChanged = true;
            }

            if (isset($validated['dimming']) && $bulb->dimming != $validated['dimming']) {
                $bulb->dimming = $validated['dimming'];
                $bulbChanged = true;
            }

            if ($bulbChanged) {
                $bulb->save();
                $bulbUpdate = true;

                if (config('clarion.node_id') == $bulb->local_node_id) {
                    $command = $this->buildCommand($bulb);
                    SendBulbCommand::dispatch($bulb->ip, $command, (string) $bulb->id);
                }

                event(new BulbStatusEvent($bulb));
            }
        }

        return $room;
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
