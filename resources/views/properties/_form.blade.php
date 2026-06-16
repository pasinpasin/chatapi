@php
    $property = $property ?? null;
    $amenitiesList = ['wifi' => 'Wi-Fi', 'parking' => 'Parking', 'breakfast' => 'Mëngjes', 'ac' => 'Klimë', 'pool' => 'Pishinë', 'pets' => 'Lejohen kafshët'];
    $selectedAmenities = old('amenities', $property->amenities ?? []);
@endphp

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="form-group">
    <label>Emri i Pronës</label>
    <input type="text" name="name" class="form-control" value="{{ old('name', $property->name ?? '') }}" required>
</div>

<div class="form-group">
    <label>Lloji</label>
    <select name="type" class="form-control" required>
        @foreach(['hotel' => 'Hotel', 'vila' => 'Vila', 'bnb' => 'B&B'] as $val => $label)
            <option value="{{ $val }}" {{ old('type', $property->type ?? '') == $val ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
</div>

<div class="form-group">
    <label>Adresa</label>
    <input type="text" name="address" class="form-control" value="{{ old('address', $property->address ?? '') }}">
</div>

<div class="row">
    <div class="col-md-6 form-group">
        <label>Latitude</label>
        <input type="text" name="lat" class="form-control" value="{{ old('lat', $property->lat ?? '') }}" placeholder="42.0683">
    </div>
    <div class="col-md-6 form-group">
        <label>Longitude</label>
        <input type="text" name="lng" class="form-control" value="{{ old('lng', $property->lng ?? '') }}" placeholder="19.5126">
    </div>
</div>

<div class="form-group">
    <label>Përshkrimi</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $property->description ?? '') }}</textarea>
</div>

<div class="form-group">
    <label>Rregullat e Hotelit (check-in/out, etj.)</label>
    <textarea name="rules" class="form-control" rows="3">{{ old('rules', $property->rules ?? '') }}</textarea>
</div>

<div class="form-group">
    <label>Pajisjet (Amenities)</label><br>
    @foreach($amenitiesList as $key => $label)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="amenities[]" value="{{ $key }}"
                {{ in_array($key, $selectedAmenities) ? 'checked' : '' }}>
            <label class="form-check-label">{{ $label }}</label>
        </div>
    @endforeach
</div>

<hr>
<h5>Kanalet e Komunikimit</h5>

<div class="form-check">
    <input class="form-check-input" type="checkbox" name="webchat_enabled" value="1"
        {{ old('webchat_enabled', $property->webchat_enabled ?? true) ? 'checked' : '' }}>
    <label class="form-check-label">Web Chat i aktivizuar</label>
</div>

<div class="form-check mb-2">
    <input class="form-check-input" type="checkbox" name="whatsapp_enabled" value="1"
        {{ old('whatsapp_enabled', $property->whatsapp_enabled ?? false) ? 'checked' : '' }}>
    <label class="form-check-label">WhatsApp i aktivizuar</label>
</div>

<div class="form-group">
    <label>Numri WhatsApp (me kod vendi, p.sh. +355...)</label>
    <input type="text" name="whatsapp_number" class="form-control" value="{{ old('whatsapp_number', $property->whatsapp_number ?? '') }}">
</div>

<hr>