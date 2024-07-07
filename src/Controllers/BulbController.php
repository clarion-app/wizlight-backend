<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\RGBColor;


class BulbController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Bulb::with('last_seen')->get();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Bulb $bulb)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $bulb = Bulb::find($id);
        if(!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }

        $update = false;
        $newState = $request->state['state'] ? true : false;
        if($bulb->state != $newState)
        {
            $bulb->state = $newState;
            $update = true;
        }

        if($bulb->red != $request->state['red'])
        {
            $bulb->red = $request->state['red'];
            $update = true;
        }

        if($bulb->green != $request->state['green'])
        {
            $bulb->green = $request->state['green'];
            $update = true;
        }

        if($bulb->blue != $request->state['blue'])
        {
            $bulb->blue = $request->state['blue'];
            $update = true;
        }

        if($bulb->dimming != $request->state['dimming'])
        {
            $bulb->dimming = $request->state['dimming'];
            $update = true;
        }
        
        if($bulb->name != $request->state['name'])
        {
            $bulb->name = $request->state['name'];
            $update = true;
        }
        
        if(!$update) return $bulb;
        $bulb->save();

        if(config('clarion.node_id') != $bulb->local_node_id)
        {
            // This bulb is not connected to this node, do not try to send commands via UDP.
            return $bulb;
        }

        $wiz = new Wiz();
        $color = new RGBColor($request->state['red'], $request->state['green'], $request->state['blue']);
//        $wiz->set_pilot_state($bulb->ip, $color, $request->state['dimming'], $bulb->temperature, $bulb->state ? 1 : 0);
        $wiz->set_pilot_state($bulb->ip, $color, $request->state['dimming'], 0, $bulb->state ? 1 : 0);
        return $bulb;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Bulb $bulb)
    {
        //
    }
}
