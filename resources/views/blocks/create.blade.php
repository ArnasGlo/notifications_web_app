@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <div class="d-flex align-items-center mb-4">
                <a href="{{ route('numbers.blocks.index', $number) }}" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h2 class="mb-0 fw-bold">Add Block Rule</h2>
                    <small class="text-muted">Number: <strong>{{ $number->number }}</strong></small>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0 ps-3">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('numbers.blocks.store', $number) }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="type" class="form-label fw-semibold">
                                Block Type <span class="text-danger">*</span>
                            </label>
                            <select name="type" id="type"
                                    class="form-select @error('type') is-invalid @enderror"
                                    required>
                                <option value="" disabled {{ old('type') ? '' : 'selected' }}>Select a type…</option>
                                <option value="number"  {{ old('type') === 'number'  ? 'selected' : '' }}>Phone Number</option>
                                <option value="user"    {{ old('type') === 'user'    ? 'selected' : '' }}>User (all their numbers)</option>
                                <option value="city"    {{ old('type') === 'city'    ? 'selected' : '' }}>City</option>
                                <option value="country" {{ old('type') === 'country' ? 'selected' : '' }}>Country</option>
                            </select>
                            @error('type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="value" class="form-label fw-semibold">
                                Value <span class="text-danger">*</span>
                            </label>
                            <input type="text"
                                   name="value"
                                   id="value"
                                   class="form-control @error('value') is-invalid @enderror"
                                   value="{{ old('value') }}"
                                   placeholder="Enter value…"
                                   required>
                            @error('value')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div id="value-hint" class="form-text text-muted">
                                Select a block type to see what to enter here.
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-ban me-2"></i> Add Block Rule
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

@push('scripts')
<script>
const hints = {
    number:  'Enter the exact phone number to block (e.g. +37060000001).',
    user:    'Enter the numeric User ID. All numbers belonging to that user will be blocked.',
    city:    'Enter a city name (e.g. Vilnius). All senders from this city will be blocked.',
    country: 'Enter a country name (e.g. Lithuania). All senders from this country will be blocked.',
};

document.getElementById('type').addEventListener('change', function () {
    document.getElementById('value-hint').textContent = hints[this.value] ?? '';
    document.getElementById('value').placeholder = this.value === 'number'
        ? '+37060000001'
        : this.value === 'user'
            ? 'User ID (e.g. 42)'
            : 'e.g. ' + (this.value === 'city' ? 'Vilnius' : 'Lithuania');
});

// Restore hint on page load if type is pre-selected (validation failure)
const typeEl = document.getElementById('type');
if (typeEl.value) typeEl.dispatchEvent(new Event('change'));
</script>
@endpush
@endsection
