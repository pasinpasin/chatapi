<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amenities' => 'array',
        'webchat_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function pointsOfInterest()
    {
        return $this->hasMany(PointOfInterest::class);
    }

    public function icalLinks()
    {
        return $this->hasMany(PropertyIcalLink::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}