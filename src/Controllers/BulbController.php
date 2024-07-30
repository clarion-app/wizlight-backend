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
     * Display a list of all bulbs.
     * @response Bulb[]
     */
    public function index(Request $request)
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
     * Change the bulb state.
     * @param  \Illuminate\Http\Request  $request
     * @param string $id
     * @response Bulb
     */
    public function update(Request $request, $id)
    {
        $rules = [
            'state' => 'required|array',
            'state.state' => 'required|boolean',
            'state.red' => 'required|integer|min:0|max:255',
            'state.green' => 'required|integer|min:0|max:255',
            'state.blue' => 'required|integer|min:0|max:255',
            'state.dimming' => 'required|integer|min:0|max:100',
            'state.name' => 'required|string',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails())
        {
            return response()->json(['errors' => $validator->errors()], 400);
        }

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

        if(config('clarion.node_id') == $bulb->local_node_id)
        {
            $wiz = new Wiz();
            $color = new RGBColor($request->state['red'], $request->state['green'], $request->state['blue']);
            $wiz->set_pilot_state($bulb->ip, $color, $request->state['dimming'], 0, $bulb->state ? 1 : 0);
        }

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
