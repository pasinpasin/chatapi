<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyIcalLink extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}