<?php

namespace ClarionApp\WizlightBackend\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;

class Bulb extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = [
        'local_node_id',
        'local_node_seen_at',
        'mac',
        'ip',
        'name',
        'model',
        'firmware_version',
        'capability_class',
        'warmth_min_kelvin',
        'warmth_max_kelvin',
        'min_brightness_pct',
        'wiz_room_id',
        'wiz_group_id',
        'group',
        'dimming',
        'state',
        'temperature',
        'red',
        'green',
        'blue',
        'signal',
        'room_id',
        'active_mode',
        'scene_id',
        'scene_speed',
        'white_warm',
        'white_cool',
        'head_ratio',
        'dual_head',
    ];

    protected $table = 'wizlight_bulbs';

    protected $casts = [
        'local_node_seen_at' => 'datetime',
        'warmth_min_kelvin' => 'integer',
        'warmth_max_kelvin' => 'integer',
        'min_brightness_pct' => 'integer',
        'wiz_room_id' => 'integer',
        'wiz_group_id' => 'integer',
        'scene_id' => 'integer',
        'scene_speed' => 'integer',
        'white_warm' => 'integer',
        'white_cool' => 'integer',
        'head_ratio' => 'integer',
        'dual_head' => 'boolean',
    ];

    public function last_seen()
    {
        return $this->hasOne(BulbLastSeen::class, 'bulb_id', 'id');
    }
}
