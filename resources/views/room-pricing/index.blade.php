@extends('layouts.admin')

@section('content_header_title', 'Çmime Sezonale - ' . $room->name)

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<a href="{{ route('properties.rooms.index', $room->property) }}" class="btn btn-secondary mb-3">
    <i class="fas fa-arrow-left"></i> Kthehu te Dhomat
</a>

<a href="{{ route('rooms.pricing.create', $room) }}" class="btn btn-primary mb-3">
    <i class="fas fa-plus"></i> Shto Periudhë Çmimi
</a>

<div class="alert alert-info">
    Çmimi bazë i kësaj dhome: <strong>{{ number_format($room->base_price, 2) }} €</strong>/natë.
    Periudhat më poshtë e mbivendosin çmimin bazë kur data përkon.
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Nga Data</th>
                    <th>Deri Datë</th>
                    <th>Çmimi</th>
                    <th>Min. Net</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pricing as $p)
                <tr>
                    <td>{{ $p->start_date->format('d/m/Y') }}</td>
                    <td>{{ $p->end_date->format('d/m/Y') }}</td>
                    <td>{{ number_format($p->price, 2) }} €</td>
                    <td>{{ $p->min_stay }}</td>
                    <td>
                        <form action="{{ route('pricing.destroy', $p) }}" method="POST" style="display:inline-block" onsubmit="return confirm('Je i sigurt?')">
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