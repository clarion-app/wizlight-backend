<?php

namespace ClarionApp\WizlightBackend\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BulbLastSeen extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'bulb_id',
        'last_seen_at',
    ];
    protected $table = 'wizlight_bulb_last_seens';
}
