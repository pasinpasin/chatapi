<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PropertyController extends Controller
{
    public function index()
    {
        $properties = auth()->user()->properties;
        return view('properties.index', compact('properties'));
    }

    public function create()
    {
        return view('properties.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateData($request);
        $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(5);
        $validated['user_id'] = auth()->id();
        $validated['amenities'] = $this->parseAmenities($request);

        Property::create($validated);

        return redirect()->route('properties.index')->with('success', 'Prona u krijua me sukses.');
    }

    public function edit(Property $property)
    {
        $this->authorizeOwner($property);
        return view('properties.edit', compact('property'));
    }

    public function update(Request $request, Property $property)
    {
        $this->authorizeOwner($property);

        $validated = $this->validateData($request);
        $validated['amenities'] = $this->parseAmenities($request);

        $property->update($validated);

        return redirect()->route('properties.index')->with('success', 'Prona u përditësua.');
    }

    public function destroy(Property $property)
    {
        $this->authorizeOwner($property);
        $property->delete();

        return redirect()->route('properties.index')->with('success', 'Prona u fshi.');
    }

    private function validateData(Request $request)
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:hotel,vila,bnb',
            'address' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'description' => 'nullable|string',
            'rules' => 'nullable|string',
            'webchat_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'whatsapp_number' => 'nullable|string|max:30',
        ]);
    }

    private function parseAmenities(Request $request)
    {
        // checkboxes vijne si array i amenities[]
        return $request->input('amenities', []);
    }

    private function authorizeOwner(Property $property)
    {
        if ($property->user_id !== auth()->id()) {
            abort(403);
        }
    }
}