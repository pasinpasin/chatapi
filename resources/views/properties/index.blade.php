@extends('layouts.admin')

@section('content_header_title', 'Pronat e Mia')

@section('content')

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<a href="{{ route('properties.create') }}" class="btn btn-primary mb-3">
    <i class="fas fa-plus"></i> Shto Pronë të Re
</a>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Emri</th>
                    <th>Lloji</th>
                    <th>Adresa</th>
                    <th>Dhoma</th>
                    <th>Web Chat</th>
                    <th>WhatsApp</th>
                    <th>Veprime</th>
                </tr>
            </thead>
            <tbody>
                @foreach($properties as $property)
                <tr>
                    <td>{{ $property->name }}</td>
                    <td>{{ ucfirst($property->type) }}</td>
                    <td>{{ $property->address }}</td>
                    <td>{{ $property->rooms()->count() }}</td>
                    <td>
                        @if($property->webchat_enabled)
                            <span class="badge bg-success">Aktiv</span>
                        @else
                            <span class="badge bg-secondary">Joaktiv</span>
                        @endif
                    </td>
                    <td>
                        @if($property->whatsapp_enabled)
                            <span class="badge bg-success">Aktiv</span>
                        @else
                            <span class="badge bg-secondary">Joaktiv</span>
                        @endif
                    </td>
                   <td>
    <a href="{{ route('properties.rooms.index', $property) }}" class="btn btn-sm btn-info">
        <i class="fas fa-bed"></i> Dhoma
    </a>
    <a href="{{ route('properties.edit', $property) }}" class="btn btn-sm btn-warning">
        <i class="fas fa-edit"></i>
    </a>
    <form action="{{ route('properties.destroy', $property) }}" method="POST" style="display:inline-block" onsubmit="return confirm('Je i sigurt?')">
        @csrf
        @method('DELETE')
        <button class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
    </form>
    <a href="{{ route('properties.poi.index', $property) }}" class="btn btn-sm btn-success">
    <i class="fas fa-map-marker-alt"></i> Pika Interesi
</a>
<a href="{{ route('properties.ical.index', $property) }}" class="btn btn-sm btn-info">
    <i class="fas fa-sync"></i> iCal
</a>
<a href="{{ route('properties.conversations.index', $property) }}" class="btn btn-sm btn-secondary">
    <i class="fas fa-comments"></i> Biseda
</a>
<button class="btn btn-sm btn-dark"
    onclick="showEmbed('{{ $property->slug }}', '{{ $property->name }}')">
    <i class="fas fa-code"></i>
</button>
</td>
                    
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
{{-- Modal per embed code --}}
<div class="modal fade" id="embedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Kodi për Faqen Tuaj</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Kopjoni këtë kod dhe vendoseni para <code>&lt;/body&gt;</code> në faqen tuaj:</p>
                <textarea class="form-control" id="embedCode" rows="3" readonly></textarea>
                <button class="btn btn-sm btn-primary mt-2" onclick="copyEmbed()">
                    <i class="fas fa-copy"></i> Kopjo
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('js')
<script>
function showEmbed(slug, name) {
    const url = '{{ config('app.url') }}';
    document.getElementById('embedCode').value =
        `<script src="${url}/widget/${slug}/embed.js"><\/script>`;
    $('#embedModal').modal('show');
}

function copyEmbed() {
    const el = document.getElementById('embedCode');
    el.select();
    document.execCommand('copy');
    alert('U kopjua!');
}
</script>
@endpush