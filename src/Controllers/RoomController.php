<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Room;
use ClarionApp\WizlightBackend\Scenes\SceneCatalogue;
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
            'active_mode' => 'nullable|string|in:rgb,warmth,white_channels,scene',
            'scene_id' => 'nullable|integer',
            'scene_speed' => 'nullable|integer|min:' . SceneCatalogue::SPEED_MIN . '|max:' . SceneCatalogue::SPEED_MAX,
        ]);

        $room = Room::with('bulbs')->find($id);
        if(!$room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        // WizlightService::updateRoomState() returns {room, capability_skips}
        // (Phase 4, US2/FR-016) — unwrap it here so callers keep getting the
        // Room model itself (unchanged shape/property access), with
        // capability_skips folded in as an extra attribute for JSON responses.
        $result = $this->service->updateRoomState($room, $validated);
        $room = $result['room'];
        $room->setAttribute('capability_skips', $result['capability_skips']);

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
}