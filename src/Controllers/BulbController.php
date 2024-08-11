<?php

namespace ClarionApp\WizlightBackend\Controllers;

use ClarionApp\WizlightBackend\Models\Bulb;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use ClarionApp\WizlightBackend\Wiz;
use ClarionApp\WizlightBackend\RGBColor;
use ClarionApp\WizlightBackend\TemperatureColor;
use Validator;
use Illuminate\Support\Facades\Log;


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
        $validated = $request->validate([
            'state' => 'required|boolean',
            'red' => 'required|integer|min:0|max:255',
            'green' => 'required|integer|min:0|max:255',
            'blue' => 'required|integer|min:0|max:255',
            'temperature' => 'required|integer|min:0|max:6500',
            'dimming' => 'required|integer|min:0|max:100',
            'name' => 'required|string',
        ]);

        $bulb = Bulb::find($id);
        if(!$bulb) {
            return response()->json(['message' => 'Bulb not found'], 404);
        }

        $update = false;
        $newState = $request->state ? true : false;
        if($bulb->state != $newState)
        {
            $bulb->state = $newState;
            $update = true;
        }

        if($bulb->red != $request->red)
        {
            $bulb->red = $request->red;
            $update = true;
        }

        if($bulb->green != $request->green)
        {
            $bulb->green = $request->green;
            $update = true;
        }

        if($bulb->blue != $request->blue)
        {
            $bulb->blue = $request->blue;
            $update = true;
        }

        if($bulb->dimming != $request->dimming)
        {
            $bulb->dimming = $request->dimming;
            $update = true;
        }
        
        if($bulb->name != $request->name)
        {
            $bulb->name = $request->name;
            $update = true;
        }

        if($bulb->temperature != $request->temperature)
        {
            $bulb->temperature = $request->temperature;
            $update = true;
        }
        
        if(!$update) return $bulb;
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
