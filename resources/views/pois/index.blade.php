@extends('layouts.admin')

@section('content_header_title', 'Pikat e Interesit - ' . $property->name)

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<a href="{{ route('properties.index') }}" class="btn btn-secondary mb-3">
    <i class="fas fa-arrow-left"></i> Kthehu te Pronat
</a>

<a href="{{ route('properties.poi.create', $property) }}" class="btn btn-primary mb-3">
    <i class="fas fa-plus"></i> Shto Pikë Interesi
</a>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Emri</th>
                    <th>Lloji</th>
                    <th>Distanca</th>
                    <th>Google Maps</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pois as $poi)
                <tr>
                    <td>{{ $poi->name }}</td>
                    <td>
                        <span class="badge bg-secondary">{{ ucfirst($poi->type) }}</span>
                    </td>
                    <td>{{ $poi->distance_text ?? '-' }}</td>
                    <td>
                        @if($poi->gmaps_link)
                            <a href="{{ $poi->gmaps_link }}" target="_blank">
                                <i class="fas fa-map-marker-alt"></i> Hap në Maps
                            </a>
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('poi.edit', $poi) }}" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                        <form action="{{ route('poi.destroy', $poi) }}" method="POST" style="display:inline-block" onsubmit="return confirm('Je i sigurt?')">
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