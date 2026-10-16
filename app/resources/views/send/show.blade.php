@extends('layouts.trader')

@section('title', 'Send ' . $send->id)

@section('content')
    {{-- The five states of a send, in the words the person reading them cares about.

         The one that matters here is paid_out, which does not exist on the buy or sell screens. On those,
         "released" means the money is free and the trade is done. On a send it means the same thing AND
         that the cash physically changed hands, because those are the same event in the ledger (see
         kudi-core/020). So the step below is not an administrative note between two others -- it is the
         step where the naira leaves the building, and a sender who saw it as greyed out while the send
         said "confirmed" would be told the wrong thing about their money. --}}
    @php
        $steps = [
            'paid_claimed' => ['You told us you paid', 'Send the cedi, then mark it here.'],
            'paid_attested' => ['We confirmed your cedi arrived', 'A person at KudiMarket is arranging the naira.'],
            'paid_out' => ['The naira has been handed over', 'Recorded against a till reference.'],
            'released' => ['Complete', 'The beneficiary has what they were sent.'],
        ];
        $order = array_keys($steps);
        $at = array_search($send->state, $order, true);
    @endphp

    <h1>Send to {{ $send->beneficiary_name }}</h1>

    @if (session('notice'))
        <div class="card"><p>{{ session('notice') }}</p></div>
    @endif

    <div class="card">
        <dl>
            {{-- "They receive", not "You receive", for the reason the create screen gives: the
                 beneficiary is named on the card below and the sender never holds the naira. --}}
            <div><dt>They receive</dt><dd class="num">{{ $figures['receive'] }}</dd></div>
            <div><dt>At, held</dt><dd class="num">{{ \App\Services\RateBoard::formatRate((float) $send->rate) }}</dd></div>
            <div><dt>Beneficiary</dt><dd>{{ $send->beneficiary_name }}</dd></div>
            <div><dt>Their phone</dt><dd class="num">{{ $send->beneficiary_phone }}</dd></div>
        </dl>
    </div>

    <div class="card">
        <div class="label">Where this has got to</div>
        <dl>
            @foreach ($steps as $state => [$title, $note])
                @php $done = $at !== false && $loop->index <= $at; @endphp
                <div>
                    <dt>{{ $title }}</dt>
                    <dd>
                        @if ($done)
                            Done
                        @else
                            <span class="note">{{ $note }}</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- The handover evidence, once there is any. Rendered from the ledger's own columns rather than
         reconstructed from the event log, because this is the record a sender is entitled to see and it
         is the same record operations wrote. Before it exists the card is absent rather than empty: an
         empty "Till reference" field reads as "there is one and it is blank". --}}
    @if ($send->delivered_reference)
        <div class="card">
            <div class="label">The handover</div>
            <dl>
                <div><dt>Till reference</dt><dd class="num">{{ $send->delivered_reference }}</dd></div>
                <div><dt>Recorded by</dt><dd>{{ $send->delivered_by }}</dd></div>
            </dl>
        </div>
    @endif

    @if ($send->state === 'paid_claimed')
        <form method="post" action="{{ route('trade.claim', $send->id) }}">
            @csrf
            <button type="submit">I have sent the cedi</button>
        </form>
        <p class="note" style="margin-top:10px">
            Mark it once your bank or mobile money confirms the payment has gone. You cannot mark it as
            delivered &mdash; a person who did not hand it over has to be the one to say so.
        </p>
    @endif

    @if ($send->state === 'open')
        <p class="note">This send is not started yet. Send the cedi, then mark it here.</p>
    @endif
@endsection