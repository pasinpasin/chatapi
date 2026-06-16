@extends('layouts.admin')

@section('content_header_title', 'Sinkronizimi iCal - ' . $property->name)

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<a href="{{ route('properties.index') }}" class="btn btn-secondary mb-3">
    <i class="fas fa-arrow-left"></i> Kthehu te Pronat
</a>
<form action="{{ route('properties.ical.sync', $property) }}" method="POST" style="display:inline-block">
    @csrf
    <button type="submit" class="btn btn-success mb-3">
        <i class="fas fa-sync"></i> Sinkronizo Tani
    </button>
</form>

{{-- Forma per shtim --}}
<div class="card mb-4">
    <div class="card-header"><b>Shto Link iCal të Ri</b></div>
    <div class="card-body">
        <form action="{{ route('properties.ical.store', $property) }}" method="POST">
            @csrf

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="form-group">
                <label>Dhoma (opsionale - lidhje iCal me dhomë specifike)</label>
                <select name="room_id" class="form-control">
                    <option value="">-- Të gjitha dhomat e pronës --</option>
                    @foreach($rooms as $room)
                        <option value="{{ $room->id }}">{{ $room->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group">
                <label>Burimi</label>
                <select name="source" class="form-control" required>
                    <option value="booking">Booking.com</option>
                    <option value="airbnb">Airbnb</option>
                    <option value="other">Tjetër</option>
                </select>
            </div>

            <div class="form-group">
                <label>iCal URL</label>
                <input type="url" name="ical_url" class="form-control" required
                    placeholder="https://ical.booking.com/v1/export?t=...">
                <small class="text-muted">
                    <b>Booking.com:</b> Property → Calendar → Sync → Export Calendar<br>
                    <b>Airbnb:</b> Calendar → Availability → Export Calendar
                </small>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-plus"></i> Shto Link
            </button>
        </form>
    </div>
</div>

{{-- Lista e link-eve --}}
<div class="card">
    <div class="card-header"><b>Link-et Aktive</b></div>
    <div class="card-body p-0">
        <table class="table table-striped mb-0">
            <thead>
                <tr>
                    <th>Burimi</th>
                    <th>Dhoma</th>
                    <th>URL</th>
                    <th>Sinkronizimi i Fundit</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody>
                @forelse($links as $link)
                <tr>
                    <td><span class="badge bg-info">{{ ucfirst($link->source) }}</span></td>
                    <td>{{ $link->room?->name ?? 'Të gjitha dhomat' }}</td>
                    <td>
                        <small class="text-muted">{{ Str::limit($link->ical_url, 50) }}</small>
                    </td>
                    <td>
                        @if($link->last_synced_at)
                            {{ $link->last_synced_at->diffForHumans() }}
                        @else
                            <span class="text-warning">Asnjëherë</span>
                        @endif
                    </td>
                    <td>
                        <form action="{{ route('ical.destroy', $link) }}" method="POST"
                            onsubmit="return confirm('Je i sigurt?')" style="display:inline-block">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">Nuk ka link iCal ende.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection