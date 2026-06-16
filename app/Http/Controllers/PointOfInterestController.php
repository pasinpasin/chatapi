<?php
namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PointOfInterest;
use Illuminate\Http\Request;

class PointOfInterestController extends Controller
{
    public function index(Property $property)
    {
        $this->authorizeOwner($property);
        $pois = $property->pointsOfInterest()->get();
        return view('pois.index', compact('property', 'pois'));
    }

    public function create(Property $property)
    {
        $this->authorizeOwner($property);
        return view('pois.create', compact('property'));
    }

 

    public function edit(PointOfInterest $poi)
    {
        $this->authorizeOwner($poi->property);
        return view('pois.edit', compact('poi'));
    }

 public function store(Request $request, Property $property)
{
    $this->authorizeOwner($property);

    $validated = $this->validateData($request);
    
    // hiq gmaps_link_manual - nuk ekziston ne DB
    $gmapsManual = $validated['gmaps_link_manual'] ?? null;
    unset($validated['gmaps_link_manual']);
    
    $validated['property_id'] = $property->id;
    $validated['gmaps_link'] = $this->buildGmapsLink($validated, null, $gmapsManual);

    PointOfInterest::create($validated);

    return redirect()->route('properties.poi.index', $property)->with('success', 'Pika u shtua.');
}

public function update(Request $request, PointOfInterest $poi)
{
    $this->authorizeOwner($poi->property);

    $validated = $this->validateData($request);
    
    // hiq gmaps_link_manual - nuk ekziston ne DB
    $gmapsManual = $validated['gmaps_link_manual'] ?? null;
    unset($validated['gmaps_link_manual']);
    
    $validated['gmaps_link'] = $this->buildGmapsLink($validated, $poi, $gmapsManual);

    $poi->update($validated);

    return redirect()->route('properties.poi.index', $poi->property)->with('success', 'Pika u përditësua.');
}

    public function destroy(PointOfInterest $poi)
    {
        $this->authorizeOwner($poi->property);
        $property = $poi->property;
        $poi->delete();

        return redirect()->route('properties.poi.index', $property)->with('success', 'Pika u fshi.');
    }

    private function validateData(Request $request)
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'description' => 'nullable|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'distance_text' => 'nullable|string|max:100',
            'gmaps_link_manual' => 'nullable|url',
        ]);
    }

    /**
     * Ndertojme link Google Maps automatikisht nga lat/lng,
     * ose perdorim linkun manual nese eshte dhene.
     */
   private function buildGmapsLink(array $data, ?PointOfInterest $poi = null, ?string $gmapsManual = null)
{
    if (!empty($gmapsManual)) {
        return $gmapsManual;
    }

    if (!empty($data['lat']) && !empty($data['lng'])) {
        return "https://www.google.com/maps/search/?api=1&query={$data['lat']},{$data['lng']}";
    }

    return $poi?->gmaps_link;
}
    private function authorizeOwner(Property $property)
    {
        if ($property->user_id !== auth()->id()) {
            abort(403);
        }
    }
}