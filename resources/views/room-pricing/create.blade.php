@extends('layouts.admin')

@section('content_header_title', 'Shto Çmim - ' . $room->name)

@section('content')

<div class="card">
    <div class="card-body">
        <form action="{{ route('rooms.pricing.store', $room) }}" method="POST">
            @csrf

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Nga Data</label>
                    <input type="date" name="start_date" class="form-control" value="{{ old('start_date') }}" required>
                </div>
                <div class="col-md-6 form-group">
                    <label>Deri Datë</label>
                    <input type="date" name="end_date" class="form-control" value="{{ old('end_date') }}" required>
                </div>
            </div>

            <div class="form-group">
                <label>Çmimi (€/natë)</label>
                <input type="number" step="0.01" name="price" class="form-control" value="{{ old('price') }}" required>
            </div>

            <div class="form-group">
                <label>Qëndrimi Minimal (netë)</label>
                <input type="number" name="min_stay" class="form-control" value="{{ old('min_stay', 1) }}" min="1" required>
            </div>

            <button type="submit" class="btn btn-primary">Ruaj</button>
            <a href="{{ route('rooms.pricing.index', $room) }}" class="btn btn-secondary">Anulo</a>
        </form>
    </div>
</div>

@endsection