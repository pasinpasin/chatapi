<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    protected $guarded = [];

    public const AMENITIES = [
        'wifi' => 'Wi-Fi',
        'parking' => 'Parking',
        'breakfast' => 'Mëngjes (Breakfast)',
        'ac' => 'Klimë / Air Conditioning (AC)',
        'pool' => 'Pishinë (Pool)',
        'pets' => 'Lejohen kafshët shtëpiake (Pets allowed)'
    ];

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