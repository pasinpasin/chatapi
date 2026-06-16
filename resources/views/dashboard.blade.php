@extends('layouts.admin')

@section('content_header_title', 'Dashboard')

@section('content')
<div class="row">
    <div class="col-lg-4 col-6">
        <div class="small-box bg-info">
            <div class="inner">
                <h3>{{ $stats['properties_count'] }}</h3>
                <p>Prona</p>
            </div>
            <div class="icon"><i class="fas fa-hotel"></i></div>
            <a href="{{ route('properties.index') }}" class="small-box-footer">
                Shiko të gjitha <i class="fas fa-arrow-circle-right"></i>
            </a>
        </div>
    </div>

    <div class="col-lg-4 col-6">
        <div class="small-box bg-success">
            <div class="inner">
                <h3>{{ $stats['rooms_count'] }}</h3>
                <p>Dhoma Totale</p>
            </div>
            <div class="icon"><i class="fas fa-bed"></i></div>
        </div>
    </div>

    <div class="col-lg-4 col-6">
        <div class="small-box bg-warning">
            <div class="inner">
                <h3>{{ $stats['conversations_count'] }}</h3>
                <p>Biseda</p>
            </div>
            <div class="icon"><i class="fas fa-comments"></i></div>
        </div>
    </div>
</div>

@if($properties->isEmpty())
    <div class="alert alert-info">
        Nuk ke asnjë pronë ende. <a href="{{ route('properties.create') }}">Shto pronën e parë</a>.
    </div>
@endif

@endsection