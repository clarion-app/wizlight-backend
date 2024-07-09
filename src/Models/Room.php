<?php

namespace ClarionApp\WizlightBackend\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MetaverseSystems\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\WizlightBackend\Models\BulbLastSeen;
use ClarionApp\WizlightBackend\Models\Bulb;

class Room extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = [
        'name',
    ];

    protected $table = 'wizlight_rooms';

    // Room has one to many relationship with bulbs
    public function bulbs()
    {
        return $this->hasMany(Bulb::class);
    }
}