<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomPricing;
use Illuminate\Http\Request;

class RoomPricingController extends Controller
{
    public function index(Room $room)
    {
        $this->authorizeOwner($room);
        $pricing = $room->pricing()->orderBy('start_date')->get();
        return view('room-pricing.index', compact('room', 'pricing'));
    }

    public function create(Room $room)
    {
        $this->authorizeOwner($room);
        return view('room-pricing.create', compact('room'));
    }

    public function store(Request $request, Room $room)
    {
        $this->authorizeOwner($room);

        $validated = $this->validateData($request);
        $validated['room_id'] = $room->id;

        RoomPricing::create($validated);

        return redirect()->route('rooms.pricing.index', $room)->with('success', 'Çmimi u shtua.');
    }

    public function destroy(RoomPricing $pricing)
    {
        $this->authorizeOwner($pricing->room);
        $room = $pricing->room;
        $pricing->delete();

        return redirect()->route('rooms.pricing.index', $room)->with('success', 'Çmimi u fshi.');
    }

    private function validateData(Request $request)
    {
        return $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'price' => 'required|numeric|min:0',
            'min_stay' => 'required|integer|min:1',
        ]);
    }

    private function authorizeOwner(Room $room)
    {
        if ($room->property->user_id !== auth()->id()) {
            abort(403);
        }
    }
}