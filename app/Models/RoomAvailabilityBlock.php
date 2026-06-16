<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomAvailabilityBlock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
