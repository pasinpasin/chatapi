@extends('layouts.admin')

@section('content_header_title', 'Modifiko: ' . $property->name)

@section('content')

<div class="card">
    <div class="card-body">
        <form action="{{ route('properties.update', $property) }}" method="POST">
            @csrf
            @method('PUT')
            @include('properties._form', ['property' => $property])

            <button type="submit" class="btn btn-primary">Ruaj Ndryshimet</button>
            <a href="{{ route('properties.index') }}" class="btn btn-secondary">Anulo</a>
        </form>
    </div>
</div>

@endsection