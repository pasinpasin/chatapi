<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Property $property)
    {
        $this->authorizeOwner($property);
        $rooms = $property->rooms()->withCount('pricing')->get();
        return view('rooms.index', compact('property', 'rooms'));
    }

    public function create(Property $property)
    {
        $this->authorizeOwner($property);
        return view('rooms.create', compact('property'));
    }

    public function store(Request $request, Property $property)
    {
        $this->authorizeOwner($property);

        $validated = $this->validateData($request);
        $validated['property_id'] = $property->id;

        Room::create($validated);

        return redirect()->route('properties.rooms.index', $property)->with('success', 'Dhoma u shtua.');
    }

    public function edit(Room $room)
    {
        $this->authorizeOwner($room->property);
        return view('rooms.edit', compact('room'));
    }

    public function update(Request $request, Room $room)
    {
        $this->authorizeOwner($room->property);

        $validated = $this->validateData($request);
        $room->update($validated);

        return redirect()->route('properties.rooms.index', $room->property)->with('success', 'Dhoma u përditësua.');
    }

    public function destroy(Room $room)
    {
        $this->authorizeOwner($room->property);
        $property = $room->property;
        $room->delete();

        return redirect()->route('properties.rooms.index', $property)->with('success', 'Dhoma u fshi.');
    }

    private function validateData(Request $request)
    {
        return $request->validate([
            'name'          => 'required|string|max:255',
            'type'          => 'nullable|string|max:100',
            'max_occupancy' => 'required|integer|min:1',
            'description'   => 'nullable|string',
            'base_price'    => 'required|numeric|min:0',
            'booking_url'   => 'nullable|url|max:1000',
        ]);
    }

    private function authorizeOwner(Property $property)
    {
        if ($property->user_id !== auth()->id()) {
            abort(403);
        }
    }
}
