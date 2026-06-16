<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomPricing extends Model
{
    protected $guarded = [];
    protected $table = 'room_pricing'; // shto kete rresht

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}