<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\RGBColor;
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
    public function show(Room $room)
    {
        return $room->load('bulbs');
    }

    /**
     * Update the specified room.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string',
        ]);
        
        $room = Room::find($id);
        if(!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        $update = false;
        if($room->name != $request->name)
        {
            $room->name = $request->name;
            $update = true;
        }

        if($update)
        {
            $room->save();
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