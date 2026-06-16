@extends('layouts.admin')

@section('content_header_title', 'Modifiko: ' . $poi->name)

@section('content')

<div class="card">
    <div class="card-body">
        <form action="{{ route('poi.update', $poi) }}" method="POST">
            @csrf
            @method('PUT')
            @include('pois._form', ['poi' => $poi])

            <button type="submit" class="btn btn-primary">Ruaj Ndryshimet</button>
            <a href="{{ route('properties.poi.index', $poi->property) }}" class="btn btn-secondary">Anulo</a>
        </form>
    </div>
</div>

@endsection