@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="d-flex align-items-center mb-3">
                <a href="{{ route('messages.index') }}" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="mb-0 fw-bold">{{ $counterpart?->number ?? 'Unknown number' }}</h2>
                    <small class="text-muted">
                        @if($myNumber)
                            via {{ $myNumber->number }}
                        @endif
                        @if($counterpart && $counterpart->status !== 'active')
                            &middot; <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </small>
                </div>

                @if($counterpart)
                    <div class="ms-auto">
                        @if($counterpartFavorite)
                            <form action="{{ route('favorites.destroy', $counterpartFavorite) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-warning" title="Remove from favorites">
                                    <i class="fas fa-star"></i>
                                </button>
                            </form>
                        @else
                            <form action="{{ route('favorites.store') }}" method="POST">
                                @csrf
                                <input type="hidden" name="number" value="{{ $counterpart->number }}">
                                <button class="btn btn-sm btn-outline-warning" title="Add to favorites">
                                    <i class="far fa-star"></i>
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            {{-- Older pages sit "above" the current one --}}
            @if($messages->hasMorePages())
                <div class="text-center mb-3">
                    <a href="{{ $messages->nextPageUrl() }}" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-arrow-up me-1"></i> Load older messages
                    </a>
                </div>
            @endif

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body p-3 p-md-4" style="background:#f7f8fa">
                    @if($messages->isEmpty())
                        <p class="text-center text-muted my-4 mb-0">No messages in this conversation yet.</p>
                    @else
                        @php $lastDate = null; @endphp
                        @foreach($messages as $message)
                            @php
                                $stamp    = $message->created_at;
                                $outbound = $accessibleIds->contains($message->sender_number_id);
                            @endphp

                            @if($stamp->toDateString() !== $lastDate)
                                <div class="text-center my-3">
                                    <span class="badge bg-secondary bg-opacity-25 text-secondary">
                                        @if($stamp->isToday()) Today
                                        @elseif($stamp->isYesterday()) Yesterday
                                        @else {{ $stamp->format('D, d M Y') }}
                                        @endif
                                    </span>
                                </div>
                                @php $lastDate = $stamp->toDateString(); @endphp
                            @endif

                            <div class="d-flex mb-2 {{ $outbound ? 'justify-content-end' : 'justify-content-start' }}">
                                <div class="px-3 py-2 rounded-3 shadow-sm {{ $outbound ? 'bg-primary text-white' : 'bg-white border' }}"
                                     style="max-width:78%">
                                    <div>{{ $message->body }}</div>
                                    <div class="small mt-1 text-end {{ $outbound ? 'text-white-50' : 'text-muted' }}">
                                        {{ $stamp->format('H:i') }}
                                        @if($message->status === 'queued')
                                            <i class="fas fa-clock ms-1" title="Queued — recipient is busy"></i>
                                        @elseif($outbound && $message->status === 'read')
                                            <i class="fas fa-check-double ms-1" title="Read"></i>
                                        @elseif($outbound)
                                            <i class="fas fa-check ms-1" title="Sent"></i>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>

            @if($myNumber && $counterpart)
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3 p-md-4">
                        <form action="{{ route('messages.store') }}" method="POST">
                            @csrf
                            <input type="hidden" name="sender_number_id" value="{{ $myNumber->id }}">
                            <input type="hidden" name="receiver_number_id" value="{{ $counterpart->id }}">

                            @include('partials.message-composer')

                            <div class="d-grid mt-3">
                                <button type="submit" class="btn btn-primary" id="sendBtn"
                                        {{ trim(old('body', '')) === '' ? 'disabled' : '' }}>
                                    <i class="fas fa-paper-plane me-2"></i> Send
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>

@push('scripts')
<script>
// Land at the live end of the thread.
window.addEventListener('load', () => window.scrollTo(0, document.body.scrollHeight));

document.addEventListener('composer:changed', function (e) {
    const btn = document.getElementById('sendBtn');
    if (btn) btn.disabled = e.target.value.trim().length === 0;
});
</script>
@endpush
@endsection
