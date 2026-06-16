<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $guarded = [];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function pricing()
    {
        return $this->hasMany(RoomPricing::class);
    }

    public function availabilityBlocks()
    {
        return $this->hasMany(RoomAvailabilityBlock::class);
    }

    public function icalLinks()
    {
        return $this->hasMany(PropertyIcalLink::class);
    }
}
