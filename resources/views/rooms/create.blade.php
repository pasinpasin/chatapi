@extends('layouts.admin')

@section('content_header_title', 'Shto Dhomë - ' . $property->name)

@section('content')

<div class="card">
    <div class="card-body">
        <form action="{{ route('properties.rooms.store', $property) }}" method="POST">
            @csrf
            @include('rooms._form')

            <button type="submit" class="btn btn-primary">Ruaj</button>
            <a href="{{ route('properties.rooms.index', $property) }}" class="btn btn-secondary">Anulo</a>
        </form>
    </div>
</div>

@endsection