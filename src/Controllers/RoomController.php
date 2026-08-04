<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ClarionApp\WizlightBackend\Services\WizlightService;

class RoomController extends Controller
{
    public function __construct(
        protected WizlightService $service,
    ) {}

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

        return $this->service->updateRoomState($room, $validated);
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
}