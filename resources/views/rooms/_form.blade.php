@php $room = $room ?? null; @endphp

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
    <label>Emri i Dhomës</label>
    <input type="text" name="name" class="form-control"
        value="{{ old('name', $room->name ?? '') }}" required
        placeholder="p.sh. Dhomë Double me Balkon">
</div>

<div class="form-group">
    <label>Lloji</label>
    <input type="text" name="type" class="form-control"
        value="{{ old('type', $room->type ?? '') }}"
        placeholder="single, double, suite, etc">
</div>

<div class="form-group">
    <label>Kapaciteti Maksimal (persona)</label>
    <input type="number" name="max_occupancy" class="form-control"
        value="{{ old('max_occupancy', $room->max_occupancy ?? 2) }}" min="1" required>
</div>

<div class="form-group">
    <label>Çmimi Bazë (€/natë)</label>
    <input type="number" name="base_price" step="0.01" class="form-control"
        value="{{ old('base_price', $room->base_price ?? '') }}" required>
    <small class="text-muted">Ky çmim përdoret kur nuk ka çmim të veçantë për një periudhë specifike.</small>
</div>

<div class="form-group">
    <label>Përshkrimi</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $room->description ?? '') }}</textarea>
</div>

<hr>
<h6 class="text-muted mb-3">Link Rezervimi</h6>

<div class="form-group">
    <label>
        Link Booking / Airbnb / Direct për këtë dhomë
        <small class="text-muted">(opsional)</small>
    </label>
    <input type="url" name="booking_url" class="form-control"
        value="{{ old('booking_url', $room->booking_url ?? '') }}"
        placeholder="https://www.booking.com/hotel/...">
    <small class="text-muted">
        Nëse plotësohet, AI do të japë këtë link specifik për dhomën.
        Nëse lihet bosh, do të përdoret link-u i përgjithshëm i pronës (nëse ekziston).
    </small>
</div>
