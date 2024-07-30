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
        'mac',
        'ip',
        'name',
        'model',
        'group',
        'dimming',
        'state',
        'temperature',
        'red',
        'green',
        'blue',
        'signal',
    ];

    protected $table = 'wizlight_bulbs';

    public function last_seen()
    {
        return $this->hasOne(BulbLastSeen::class, 'bulb_id', 'id');
    }
}
