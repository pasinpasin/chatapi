@php
    $poi = $poi ?? null;
    $types = ['restaurant' => 'Restorant', 'bar' => 'Bar/Kafe', 'beach' => 'Plazh', 'historic' => 'Vend Historik', 'shop' => 'Dyqan', 'other' => 'Tjetër'];
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
    <label>Emri</label>
    <input type="text" name="name" class="form-control" value="{{ old('name', $poi->name ?? '') }}" required placeholder="p.sh. Plazhi i Shkodrës">
</div>

<div class="form-group">
    <label>Lloji</label>
    <select name="type" class="form-control" required>
        @foreach($types as $val => $label)
            <option value="{{ $val }}" {{ old('type', $poi->type ?? '') == $val ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
</div>

<div class="form-group">
    <label>Përshkrimi</label>
    <textarea name="description" class="form-control" rows="2">{{ old('description', $poi->description ?? '') }}</textarea>
</div>

<div class="form-group">
    <label>Sa larg është nga prona? (tekst)</label>
    <input type="text" name="distance_text" class="form-control" value="{{ old('distance_text', $poi->distance_text ?? '') }}" placeholder="p.sh. 5 min me këmbë, 2km">
</div>

<hr>
<h6>Lokacioni (zgjidh një metodë)</h6>

<div class="row">
    <div class="col-md-6 form-group">
        <label>Latitude</label>
        <input type="text" name="lat" class="form-control" value="{{ old('lat', $poi->lat ?? '') }}" placeholder="42.0683">
    </div>
    <div class="col-md-6 form-group">
        <label>Longitude</label>
        <input type="text" name="lng" class="form-control" value="{{ old('lng', $poi->lng ?? '') }}" placeholder="19.5126">
    </div>
</div>

<div class="form-group">
    <label>OSE: Link Google Maps (kopjuar nga "Share")</label>
    <input type="url" name="gmaps_link_manual" class="form-control" placeholder="https://maps.app.goo.gl/...">
    <small class="text-muted">
        Nëse nuk e di lat/lng, hap Google Maps, gjej vendin, kliko "Share" dhe ngjit linkun këtu.
        @if($poi && $poi->gmaps_link)
            <br>Link aktual: <a href="{{ $poi->gmaps_link }}" target="_blank">{{ $poi->gmaps_link }}</a>
        @endif
    </small>
</div>