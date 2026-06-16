@extends('layouts.admin')

@section('content_header_title', 'Biseda #' . $conversation->id)

@section('content')

<a href="{{ route('properties.conversations.index', $conversation->property) }}" class="btn btn-secondary mb-3">
    <i class="fas fa-arrow-left"></i> Kthehu te Bisedat
</a>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-info"><i class="fas fa-globe"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Kanali</span>
                <span class="info-box-number">{{ ucfirst($conversation->channel) }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-success"><i class="fas fa-comments"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Mesazhe</span>
                <span class="info-box-number">{{ $messages->count() }}</span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="info-box">
            <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Filloi</span>
                <span class="info-box-number">{{ $conversation->created_at->format('d/m/Y H:i') }}</span>
            </div>
        </div>
    </div>
</div>

{{-- Chat View --}}
<div class="card">
    <div class="card-header"><b>Mesazhet</b></div>
    <div class="card-body" style="background:#f0f2f5; padding: 20px;">
        <div style="display:flex; flex-direction:column; gap:12px; max-width:700px; margin:0 auto;">
            @foreach($messages as $msg)
                <div style="display:flex; justify-content: {{ $msg->role === 'user' ? 'flex-end' : 'flex-start' }};">
                    <div style="
                        max-width: 75%;
                        padding: 10px 14px;
                        border-radius: 12px;
                        font-size: 14px;
                        line-height: 1.5;
                        background: {{ $msg->role === 'user' ? '#1a73e8' : 'white' }};
                        color: {{ $msg->role === 'user' ? 'white' : '#333' }};
                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                    ">
                        <div style="font-size:11px; opacity:0.7; margin-bottom:4px;">
                            {{ $msg->role === 'user' ? 'Klienti' : 'AI Asistenti' }} •
                            {{ $msg->created_at->format('H:i') }}
                        </div>
                        {{ $msg->content }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

@endsection