<?php

namespace ClarionApp\WizlightBackend\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;
use ClarionApp\WizlightBackend\Models\Bulb;

class Room extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = [
        'name',
        'dimming',
        'state',
        'temperature',
        'red',
        'green',
        'blue',
        'local_node_id',
        'active_mode',
        'scene_id',
        'scene_speed',
    ];

    protected $casts = [
        'scene_id' => 'integer',
        'scene_speed' => 'integer',
    ];

    protected $table = 'wizlight_rooms';

    // Room has one to many relationship with bulbs
    public function bulbs()
    {
        return $this->hasMany(Bulb::class);
    }
}