<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Conversation;

class DashboardController extends Controller
{
    public function index()
    {
        $properties = auth()->user()->properties;
        $propertyIds = $properties->pluck('id');

        $stats = [
            'properties_count' => $properties->count(),
            'rooms_count' => $properties->sum(fn($p) => $p->rooms()->count()),
            'conversations_count' => Conversation::whereIn('property_id', $propertyIds)->count(),
        ];

        return view('dashboard', compact('properties', 'stats'));
    }
}
