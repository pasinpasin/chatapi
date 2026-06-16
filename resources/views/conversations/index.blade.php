@extends('layouts.admin')

@section('content_header_title', 'Bisedat - ' . $property->name)

@section('content')

<a href="{{ route('properties.index') }}" class="btn btn-secondary mb-3">
    <i class="fas fa-arrow-left"></i> Kthehu te Pronat
</a>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-striped mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Kanali</th>
                    <th>Gjuha</th>
                    <th>Mesazhe</th>
                    <th>Filloi</th>
                    <th>Mesazhi i Fundit</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody>
                @forelse($conversations as $conv)
                <tr>
                    <td>{{ $conv->id }}</td>
                    <td>
                        @if($conv->channel === 'web')
                            <span class="badge bg-info"><i class="fas fa-globe"></i> Web</span>
                        @else
                            <span class="badge bg-success"><i class="fab fa-whatsapp"></i> WhatsApp</span>
                        @endif
                    </td>
                    <td>{{ strtoupper($conv->language ?? '?') }}</td>
                    <td>{{ $conv->messages_count }}</td>
                    <td>{{ $conv->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $conv->updated_at->diffForHumans() }}</td>
                    <td>
                        <a href="{{ route('conversations.show', $conv) }}" class="btn btn-sm btn-primary">
                            <i class="fas fa-eye"></i> Shiko
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-3">Nuk ka biseda ende.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($conversations->hasPages())
    <div class="card-footer">
        {{ $conversations->links() }}
    </div>
    @endif
</div>

@endsection