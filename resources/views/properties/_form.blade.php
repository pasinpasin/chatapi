@php $property = $property ?? null; @endphp

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row">
    <div class="col-md-8 form-group">
        <label>Emri i Pronës</label>
        <input type="text" name="name" class="form-control" value="{{ old('name', $property->name ?? '') }}" required>
    </div>
    <div class="col-md-4 form-group">
        <label>Lloji</label>
        <select name="type" class="form-control" required>
            <option value="hotel"  {{ old('type', $property->type ?? '') === 'hotel' ? 'selected' : '' }}>Hotel</option>
            <option value="vila"   {{ old('type', $property->type ?? '') === 'vila'  ? 'selected' : '' }}>Vila</option>
            <option value="bnb"    {{ old('type', $property->type ?? '') === 'bnb'   ? 'selected' : '' }}>B&B / Apartament</option>
        </select>
    </div>
</div>

<div class="form-group">
    <label>Adresa</label>
    <input type="text" name="address" class="form-control" value="{{ old('address', $property->address ?? '') }}">
</div>

<div class="row">
    <div class="col-md-6 form-group">
        <label>Gjerësia Gjeografike (Lat)</label>
        <input type="text" name="lat" class="form-control" value="{{ old('lat', $property->lat ?? '') }}">
    </div>
    <div class="col-md-6 form-group">
        <label>Gjatësia Gjeografike (Lng)</label>
        <input type="text" name="lng" class="form-control" value="{{ old('lng', $property->lng ?? '') }}">
    </div>
</div>

<div class="form-group">
    <label>Përshkrimi</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $property->description ?? '') }}</textarea>
</div>

<div class="form-group">
    <label>Rregullat e Pronës</label>
    <textarea name="rules" class="form-control" rows="2">{{ old('rules', $property->rules ?? '') }}</textarea>
</div>

<div class="form-group">
    <label>Pajisjet (Amenities)</label>
    <div class="row">
        @foreach(\App\Models\Property::AMENITIES as $key => $label)
        <div class="col-md-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="amenities[]" value="{{ $key }}" id="amenity_{{ $key }}"
                    {{ in_array($key, old('amenities', $property->amenities ?? [])) ? 'checked' : '' }}>
                <label class="form-check-label" for="amenity_{{ $key }}">{{ $label }}</label>
            </div>
        </div>
        @endforeach
    </div>
</div>

<hr>
<h6 class="text-muted mb-3">Kanalet e Komunikimit</h6>

<div class="row">
    <div class="col-md-6 form-group">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="webchat_enabled" id="webchat_enabled" value="1"
                {{ old('webchat_enabled', $property->webchat_enabled ?? false) ? 'checked' : '' }}>
            <label class="form-check-label" for="webchat_enabled">Aktivizo Web Chat</label>
        </div>
    </div>
    <div class="col-md-6 form-group">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="whatsapp_enabled" id="whatsapp_enabled" value="1"
                {{ old('whatsapp_enabled', $property->whatsapp_enabled ?? false) ? 'checked' : '' }}>
            <label class="form-check-label" for="whatsapp_enabled">Aktivizo WhatsApp</label>
        </div>
    </div>
</div>

<div class="form-group" id="whatsapp_number_group">
    <label>Numri WhatsApp (Phone Number ID nga Meta)</label>
    <input type="text" name="whatsapp_number" class="form-control"
        value="{{ old('whatsapp_number', $property->whatsapp_number ?? '') }}"
        placeholder="p.sh. 123456789012345">
</div>

<hr>
<h6 class="text-muted mb-3">Link Rezervimi</h6>

<div class="form-group">
    <label>
        Link Booking / Airbnb / Direct
        <small class="text-muted">(opsional — përdoret si fallback nëse dhoma nuk ka link të vetin)</small>
    </label>
    <input type="url" name="booking_url" class="form-control"
        value="{{ old('booking_url', $property->booking_url ?? '') }}"
        placeholder="https://www.booking.com/hotel/...">
    <small class="text-muted">Ky link do t'i jepet klientit nga AI kur pyet për rezervim, nëse dhoma nuk ka link specifik.</small>
</div>

<hr>
<h6 class="text-muted mb-3">Të tjera</h6>

<div class="form-group">
    <label>Ranking (prioritet, 0 = më i larti)</label>
    <input type="number" name="ranking" class="form-control" value="{{ old('ranking', $property->ranking ?? 0) }}" min="0">
</div>

<script>
    document.getElementById('whatsapp_enabled').addEventListener('change', function() {
        document.getElementById('whatsapp_number_group').style.display = this.checked ? 'block' : 'none';
    });
    // init
    document.getElementById('whatsapp_number_group').style.display =
        document.getElementById('whatsapp_enabled').checked ? 'block' : 'none';
</script>
