@extends('layouts.admin')

@section('content_header_title', 'Modifiko Dhomën: ' . $room->name)

@section('content')

<div class="card">
    <div class="card-body">
        <form action="{{ route('rooms.update', $room) }}" method="POST">
            @csrf
            @method('PUT')
            @include('rooms._form', ['room' => $room])

            <button type="submit" class="btn btn-primary">Ruaj Ndryshimet</button>
            <a href="{{ route('properties.rooms.index', $room->property) }}" class="btn btn-secondary">Anulo</a>
        </form>
    </div>
</div>

@endsection