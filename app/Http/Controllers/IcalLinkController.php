<?php
namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyIcalLink;
use Illuminate\Http\Request;

class IcalLinkController extends Controller
{
    public function index(Property $property)
    {
        $this->authorizeOwner($property);
        $links = $property->icalLinks()->with('room')->get();
        $rooms = $property->rooms;
        return view('ical.index', compact('property', 'links', 'rooms'));
    }

    public function store(Request $request, Property $property)
    {
        $this->authorizeOwner($property);

        $request->validate([
            'source'   => 'required|in:booking,airbnb,other',
            'ical_url' => 'required|url|max:1000',
            'room_id'  => 'nullable|exists:rooms,id',
        ]);

        PropertyIcalLink::create([
            'property_id' => $property->id,
            'room_id'     => $request->room_id,
            'source'      => $request->source,
            'ical_url'    => $request->ical_url,
        ]);

        return redirect()->route('properties.ical.index', $property)->with('success', 'iCal link u shtua.');
    }

    public function destroy(PropertyIcalLink $icalLink)
    {
        $this->authorizeOwner($icalLink->property);
        $property = $icalLink->property;
        $icalLink->delete();

        return redirect()->route('properties.ical.index', $property)->with('success', 'Link u fshi.');
    }

    private function authorizeOwner(Property $property)
    {
        if ($property->user_id !== auth()->id()) {
            abort(403);
        }
    }

    public function syncNow(Property $property, \App\Services\IcalSyncService $service)
{
    $this->authorizeOwner($property);

    $results = $service->syncAll();

    $synced = collect($results)->where('status', 'ok')->sum('synced');
    $errors = collect($results)->where('status', 'error')->count();

    $message = "Sinkronizimi përfundoi: {$synced} blloqe të importuara.";
    if ($errors > 0) {
        $message .= " {$errors} link me gabim.";
    }

    return redirect()->route('properties.ical.index', $property)->with('success', $message);
}
}