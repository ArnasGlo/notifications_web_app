@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="d-flex align-items-center mb-4">
                <a href="{{ route('messages.index') }}" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h2 class="mb-0 fw-bold">Compose Message</h2>
            </div>

            {{-- Flash errors --}}
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{-- Progress steps --}}
            <div class="d-flex align-items-center mb-4 gap-2">
                <div class="step-indicator active" id="step-ind-1">
                    <span class="step-num">1</span> <span class="step-label d-none d-sm-inline">From</span>
                </div>
                <div class="step-line"></div>
                <div class="step-indicator" id="step-ind-2">
                    <span class="step-num">2</span> <span class="step-label d-none d-sm-inline">To</span>
                </div>
                <div class="step-line"></div>
                <div class="step-indicator" id="step-ind-3">
                    <span class="step-num">3</span> <span class="step-label d-none d-sm-inline">Message</span>
                </div>
            </div>

            <form action="{{ route('messages.store') }}" method="POST" id="composeForm">
                @csrf
                <input type="hidden" name="sender_number_id"   id="senderNumberId"   value="{{ old('sender_number_id') }}">
                <input type="hidden" name="receiver_number_id" id="receiverNumberId" value="{{ old('receiver_number_id') }}">

                {{-- ── Step 1: Send From ─────────────────────────────────── --}}
                <div class="card border-0 shadow-sm mb-3" id="step1">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3">
                            <span class="badge bg-primary me-2">1</span> Send from which number?
                        </h5>

                        @if($myNumbers->isEmpty())
                            <div class="alert alert-warning mb-0">
                                You have no active numbers. <a href="{{ route('numbers.create') }}">Add one first.</a>
                            </div>
                        @else
                            <div class="row g-2">
                                @foreach($myNumbers as $num)
                                <div class="col-md-6">
                                    <div class="number-card p-3 rounded border cursor-pointer {{ old('sender_number_id') == $num->id ? 'selected' : '' }}"
                                         data-id="{{ $num->id }}"
                                         onclick="selectSender({{ $num->id }}, this)">
                                        <div class="fw-semibold">{{ $num->number }}</div>
                                        <small class="text-muted">
                                            {{ collect([$num->city, $num->country])->filter()->implode(', ') ?: 'No location' }}
                                        </small>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- ── Step 2: Send To ───────────────────────────────────── --}}
                <div class="card border-0 shadow-sm mb-3 {{ !old('sender_number_id') ? 'opacity-50' : '' }}"
                     id="step2" style="{{ !old('sender_number_id') ? 'pointer-events:none' : '' }}">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3">
                            <span class="badge bg-primary me-2">2</span> Recipient number
                        </h5>
                        <div class="input-group">
                            <input type="text"
                                   id="receiverInput"
                                   class="form-control"
                                   placeholder="+37060000001"
                                   value="{{ old('_receiver_display') }}"
                                   autocomplete="off">
                            <button class="btn btn-outline-secondary" type="button" onclick="lookupNumber()">
                                <i class="fas fa-search"></i> Lookup
                            </button>
                        </div>
                        <div id="receiverFeedback" class="mt-2 small"></div>
                    </div>
                </div>

                {{-- ── Step 3: Message body (free text or /template) ─────── --}}
                <div class="card border-0 shadow-sm mb-4 {{ !old('receiver_number_id') ? 'opacity-50' : '' }}"
                     id="step3" style="{{ !old('receiver_number_id') ? 'pointer-events:none' : '' }}">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3">
                            <span class="badge bg-primary me-2">3</span> Write message
                        </h5>

                        @include('partials.message-composer')
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-success btn-lg" id="sendBtn"
                            {{ trim(old('body', '')) === '' ? 'disabled' : '' }}>
                        <i class="fas fa-paper-plane me-2"></i> Send Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.number-card {
    cursor: pointer;
    transition: all .15s ease;
    background: #fff;
}
.number-card:hover { border-color: #0d6efd !important; background: #f0f6ff; }
.number-card.selected { border-color: #0d6efd !important; background: #e8f0fe; }

.step-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #adb5bd;
    font-size: .875rem;
    font-weight: 600;
}
.step-indicator.active { color: #0d6efd; }
.step-indicator.done   { color: #198754; }
.step-num {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: currentColor;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .75rem;
    flex-shrink: 0;
}
.step-label { color: inherit; }
.step-line {
    flex: 1;
    height: 2px;
    background: #dee2e6;
    min-width: 20px;
}
</style>

@push('scripts')
<script>

// ── Step 1 ──────────────────────────────────────────────
function selectSender(id, el) {
    document.querySelectorAll('.number-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('senderNumberId').value = id;

    // Unlock step 2
    const step2 = document.getElementById('step2');
    step2.classList.remove('opacity-50');
    step2.style.pointerEvents = '';

    setStepDone(1);
    setStepActive(2);
}

// ── Step 2 ──────────────────────────────────────────────
async function lookupNumber() {
    const num = document.getElementById('receiverInput').value.trim();
    const feedback = document.getElementById('receiverFeedback');
    if (!num) { feedback.innerHTML = '<span class="text-danger">Enter a number first.</span>'; return; }

    feedback.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>Looking up...</span>';

    try {
        const resp = await fetch(`/api/numbers/lookup?number=${encodeURIComponent(num)}`);
        const data = await resp.json();

        if (data && data.id) {
            document.getElementById('receiverNumberId').value = data.id;
            feedback.innerHTML = `<span class="text-success"><i class="fas fa-check-circle me-1"></i>Found: <strong>${data.number}</strong></span>`;

            // Unlock step 3
            const step3 = document.getElementById('step3');
            step3.classList.remove('opacity-50');
            step3.style.pointerEvents = '';

            setStepDone(2);
            setStepActive(3);
        } else {
            document.getElementById('receiverNumberId').value = '';
            feedback.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Number not found on this platform.</span>';
        }
    } catch(e) {
        feedback.innerHTML = '<span class="text-danger">Lookup failed. Try again.</span>';
    }
}

// Allow pressing Enter in the recipient field
document.getElementById('receiverInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); lookupNumber(); }
});

// ── Step 3 ──────────────────────────────────────────────
// The composer partial fires composer:changed on every edit, including when
// a /template is inserted, so the button just tracks whether there's text.
document.addEventListener('composer:changed', function (e) {
    const hasText = e.target.value.trim().length > 0;
    document.getElementById('sendBtn').disabled = !hasText;
    if (hasText) setStepDone(3);
});

// ── Step indicators ──────────────────────────────────────
function setStepActive(n) {
    document.getElementById(`step-ind-${n}`).classList.add('active');
}
function setStepDone(n) {
    const el = document.getElementById(`step-ind-${n}`);
    el.classList.remove('active');
    el.classList.add('done');
}
</script>
@endpush
@endsection
