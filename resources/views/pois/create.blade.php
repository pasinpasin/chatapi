@extends('layouts.admin')

@section('content_header_title', 'Shto Pikë Interesi - ' . $property->name)

@section('content')

<div class="card">
    <div class="card-body">
        <form action="{{ route('properties.poi.store', $property) }}" method="POST">
            @csrf
            @include('pois._form')

            <button type="submit" class="btn btn-primary">Ruaj</button>
            <a href="{{ route('properties.poi.index', $property) }}" class="btn btn-secondary">Anulo</a>
        </form>
    </div>
</div>

@endsection