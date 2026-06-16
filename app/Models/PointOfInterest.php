<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointOfInterest extends Model
{
    protected $guarded = [];
    protected $table = 'points_of_interest'; // shto kete rresht

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
