<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\RGBColor;
use ClarionApp\WizlightBackend\TemperatureColor;
use ClarionApp\WizlightBackend\Events\BulbStatusEvent;
use Illuminate\Support\Facades\Log;

// A rest controller for creating and managing rooms, and adding bulbs to them.
class RoomController extends Controller
{
    /**
     * Display a listing of rooms with their bulbs.
     */
    public function index()
    {
        return Room::with('bulbs')->get();
    }

    /**
     * Create a new room to contain bulbs.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
        ]);
        $room = new Room();
        $room->name = $request->name;
        $room->local_node_id = config('clarion.node_id');
        $room->save();
        return $room;
    }

    /**
     * Display the specified room with its bulbs.
     */
    public function show($id)
    {
        $room = Room::find($id);
        if(!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }
        
        return $room->load('bulbs');
    }

    /**
     * Update the specified room.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'state' => 'nullable|boolean',
            'red' => 'nullable|integer|min:0|max:255',
            'green' => 'nullable|integer|min:0|max:255',
            'blue' => 'nullable|integer|min:0|max:255',
            'temperature' => 'nullable|integer|min:0|max:6500',
            'dimming' => 'nullable|integer|min:0|max:100',
            'name' => 'nullable|string',
        ]);
        
        $room = Room::with('bulbs')->find($id);
        if(!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        $update = false;
        if($request->name && $room->name != $request->name)
        {
            $room->name = $request->name;
            $update = true;
        }

        $bulbUpdate = false;
        foreach($room->bulbs as $bulb)
        {
            if(isset($request->state) && $bulb->state != $request->state)
            {
                $bulb->state = $request->state;
                $bulbUpdate = true;
            }
            if(isset($request->red) && $bulb->red != $request->red)
            {
                $bulb->red = $request->red;
                $bulbUpdate = true;
            }
            if(isset($request->green) && $bulb->green != $request->green)
            {
                $bulb->green = $request->green;
                $bulbUpdate = true;
            }
            if(isset($request->blue) && $bulb->blue != $request->blue)
            {
                $bulb->blue = $request->blue;
                $bulbUpdate = true;
            }
            if(isset($request->temperature) && $bulb->temperature != $request->temperature)
            {
                $bulb->temperature = $request->temperature;
                $bulbUpdate = true;
            }
            if(isset($request->dimming) && $bulb->dimming != $request->dimming)
            {
                $bulb->dimming = $request->dimming;
                $bulbUpdate = true;
            }
        }

        if($update)
        {
            $room->save();
        }

        if($bulbUpdate)
        {
            foreach($room->bulbs as $bulb)
            {
                $bulb->save();
                if(config('clarion.node_id') == $bulb->local_node_id)
                {
                    $wiz = new Wiz();
                    $color = "";
                    if($bulb->red == 0 && $bulb->green == 0 && $bulb->blue == 0)
                    {
                        $color = (new TemperatureColor($bulb->temperature))->getValue();
                        $wiz->set_pilot_state($bulb->ip, new RGBColor(0, 0, 0), $request->dimming, $color, $bulb->state ? 1 : 0);
                    }
                    else
                    {
                        $color = new RGBColor($bulb->red, $bulb->green, $bulb->blue);
                        $wiz->set_pilot_state($bulb->ip, $color, $request->dimming, 0, $bulb->state ? 1 : 0);
                    }
                    //$color = new RGBColor($request->state['red'], $request->state['green'], $request->state['blue']);
                    
                }

                event(new BulbStatusEvent($bulb));
            }
        }

        return $room;
    }

    /**
     * Remove the specified room.
     */
    public function destroy($id)
    {
        $room = Room::find($id);
        if(!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        $room->delete();
        return response()->json(['message' => 'Room deleted']);
    }

    /**
     * Add a bulb to the room.
     */
    public function addBulb(Request $request, $id)
    {
        $room = Room::find($id);
        if(!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        $bulb = Bulb::find($request->bulb_id);
        if(!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }

        $bulb->room_id = $room->id;
        $bulb->save();
        return $room->load('bulbs');
    }

    /**
     * Remove a bulb from the room.
     */
    public function removeBulb(Request $request, $id)
    {
        $room = Room::find($id);
        if(!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }
        $bulb = Bulb::find($request->bulb_id);
        if(!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }
        $bulb->room_id = null;
        $bulb->save();
        return $room->load('bulbs');
    }
}