@extends('layouts.admin')

@section('content_header_title', 'Dhomat - ' . $property->name)

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<a href="{{ route('properties.index') }}" class="btn btn-secondary mb-3">
    <i class="fas fa-arrow-left"></i> Kthehu te Pronat
</a>

<a href="{{ route('properties.rooms.create', $property) }}" class="btn btn-primary mb-3">
    <i class="fas fa-plus"></i> Shto Dhomë
</a>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Emri</th>
                    <th>Lloji</th>
                    <th>Kapaciteti</th>
                    <th>Çmimi Bazë</th>
                    <th>Çmime të Veçanta</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rooms as $room)
                <tr>
                    <td>{{ $room->name }}</td>
                    <td>{{ $room->type }}</td>
                    <td>{{ $room->max_occupancy }} persona</td>
                    <td>{{ number_format($room->base_price, 2) }} €</td>
                    <td>
                        <a href="{{ route('rooms.pricing.index', $room) }}">
                            {{ $room->pricing_count }} periudha
                        </a>
                    </td>
                    <td>
                        <a href="{{ route('rooms.edit', $room) }}" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                        <form action="{{ route('rooms.destroy', $room) }}" method="POST" style="display:inline-block" onsubmit="return confirm('Je i sigurt?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection