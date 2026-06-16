@extends('layouts.admin')

@section('content_header_title', 'Shto Pronë të Re')

@section('content')

<div class="card">
    <div class="card-body">
        <form action="{{ route('properties.store') }}" method="POST">
            @csrf
            @include('properties._form')

            <button type="submit" class="btn btn-primary">Ruaj</button>
            <a href="{{ route('properties.index') }}" class="btn btn-secondary">Anulo</a>
        </form>
    </div>
</div>

@endsection