<?php

namespace ClarionApp\WizlightBackend\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MetaverseSystems\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;

class Bulb extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = [
        'mac',
        'ip',
        'local_node_id',
    ];

    protected $table = 'wizlight_bulbs';

    public function last_seen()
    {
        return $this->hasOne(BulbLastSeen::class, 'bulb_id', 'id');
    }
}
