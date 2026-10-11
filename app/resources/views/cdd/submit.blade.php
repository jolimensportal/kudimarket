@extends('layouts.trader')

@section('title', 'Your identity details')

@section('content')
    <p><a href="{{ route('trade.cdd.show') }}">&larr; Your identity details</a></p>

    <h1>Your identity details</h1>

    {{-- Words first, fields second. Every field below is a record the Financial Intelligence Centre can
         be shown, which is why "source of funds" is asked in a sentence rather than chosen from a list:
         the list is what we group by, the sentence is what an officer reads. --}}
    <div class="card">
        <div class="label">What we are asking, and why</div>
        <p class="note">
            We are required to identify every customer and record where their money comes from. Your
            document number and address are held for five years and can be asked for by the regulator.
            We never file a report about you without a reason, and we never tell anyone we have.
        </p>
    </div>

    <form method="post" action="{{ route('trade.cdd.store') }}">
        @csrf

        <label for="doc_type">Document</label>
        <select id="doc_type" name="doc_type" required>
            @foreach ([
                'ghana_card' => 'Ghana Card',
                'non_citizen_ecowas' => 'Non-Citizen ECOWAS Card',
                'ecowas' => 'ECOWAS ID or passport',
                'passport' => 'Passport',
                'other' => 'Something else',
            ] as $value => $label)
                <option value="{{ $value }}" @selected(old('doc_type', 'ghana_card') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('doc_type')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="doc_number">Document number</label>
        <input id="doc_number" name="doc_number" type="text" value="{{ old('doc_number') }}" required autocomplete="off">
        @error('doc_number')<p class="error" role="alert">{{ $message }}</p>@enderror

        <div class="grid2">
            <div>
                <label for="doc_issued_on">Issued <span class="hint">(optional)</span></label>
                <input id="doc_issued_on" name="doc_issued_on" type="date" value="{{ old('doc_issued_on') }}">
                @error('doc_issued_on')<p class="error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="doc_expires_on">Expires <span class="hint">(optional)</span></label>
                <input id="doc_expires_on" name="doc_expires_on" type="date" value="{{ old('doc_expires_on') }}">
                @error('doc_expires_on')<p class="error" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>

        <label for="residential_address">Where you live</label>
        <input id="residential_address" name="residential_address" type="text" value="{{ old('residential_address') }}" required>
        @error('residential_address')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="purpose_code">What you are using KudiMarket for</label>
        <select id="purpose_code" name="purpose_code" required>
            @foreach ([
                'business' => 'Trading — my own business',
                'salary' => 'Pay and salary',
                'remittance' => 'Sending money to family',
                'savings' => 'Moving my savings',
                'investment' => 'Investing',
                'other' => 'Something else',
            ] as $value => $label)
                <option value="{{ $value }}" @selected(old('purpose_code', 'business') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('purpose_code')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="purpose_detail">In a sentence <span class="hint">(optional)</span></label>
        <textarea id="purpose_detail" name="purpose_detail" rows="3">{{ old('purpose_detail') }}</textarea>
        @error('purpose_detail')<p class="error" role="alert">{{ $message }}</p>@enderror

        <label for="source_of_funds">Where the money comes from</label>
        <textarea id="source_of_funds" name="source_of_funds" rows="4" required
                  placeholder="I run a provisions shop in Madina and move cedi through my own MTN number.">{{ old('source_of_funds') }}</textarea>
        @error('source_of_funds')<p class="error" role="alert">{{ $message }}</p>@enderror

        <p class="note">
            Write it however you like. A sentence is plenty. What we cannot do is guess, because this is
            a record we may have to show somebody.
        </p>

        <button type="submit">Send these details</button>
    </form>
@endsection