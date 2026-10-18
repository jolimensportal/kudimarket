{{-- The playable send. The strongest objection on a money product is "show me it works", and a rate
     card answers it with a number rather than an interface. Every figure here is computed in
     LandingController from the live rate; none is typed in. The screens are labelled a demo because
     they are one — nothing here moves money. --}}
@if ($demo)
    <div class="device" data-send-demo data-rate="{{ $demo['raw'] }}">
        <div class="device__frame">
            <div class="device__screen">

                <p class="device__status">9:41</p>

                <ol class="device__steps">
                    <li class="on"></li><li></li><li></li><li></li><li></li>
                </ol>

                <div class="device__body">
                <section class="device__panel" data-screen="amount" aria-label="Amount">
                    <p class="device__label">You send</p>
                    <p class="device__figure" data-bind="send">₵{{ $demo['amounts'][0]['send'] }}</p>
                    <div class="device__chips" role="group" aria-label="Choose an amount">
                        @foreach ($demo['amounts'] as $index => $amount)
                            <button type="button"
                                    class="device__chip{{ $index === 0 ? ' is-on' : '' }}"
                                    data-amount="{{ $amount['value'] }}"
                                    aria-pressed="{{ $index === 0 ? 'true' : 'false' }}">₵{{ \App\Support\MoneyFormatter::format($amount['value'], 0) }}</button>
                        @endforeach
                    </div>
                    <p class="device__note">1 cedi = {{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }} naira today</p>
                    <div class="device__actions">
                        <button type="button" data-go="rate">Continue</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="rate" aria-label="Rate" hidden>
                    <p class="device__label">Today's rate</p>
                    <p class="device__figure">{{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }}</p>
                    <p class="device__note">1 cedi = {{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }} naira</p>
                    <div class="device__card">
                        <span class="device__tag">Rate locked</span>
                        <p class="device__line">Amara receives <b data-bind="receive">₦{{ $demo['amounts'][0]['receive'] }}</b></p>
                        @if ($feePercent !== null)
                            <p class="device__line">Our fee {{ $feePercent }}%{{ $feeBps !== null ? ' · '.$feeBps.' bps, charged on the send' : '' }}</p>
                        @endif
                    </div>
                    <div class="device__actions">
                        <button type="button" data-go="receiver">Continue</button>
                        <button type="button" class="device__ghost" data-back>Back</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="receiver" aria-label="Receiver" hidden>
                    <p class="device__label">Send to</p>
                    <div class="device__person">
                        <span class="device__avatar" aria-hidden="true">AO</span>
                        <span class="device__who">
                            <b>Amara Okafor</b>
                            <span>+234 803 555 0142 · Nigeria</span>
                        </span>
                    </div>
                    <p class="device__note">Naira lands with her. Not a promise — a transfer.</p>
                    <div class="device__actions">
                        <button type="button" data-go="confirm">Continue</button>
                        <button type="button" class="device__ghost" data-back>Back</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="confirm" aria-label="Confirm" hidden>
                    <p class="device__label">Confirm</p>
                    <div class="device__words">
                        <p>You're sending <b data-bind="send">₵{{ $demo['amounts'][0]['send'] }}</b> to <b>Amara Okafor</b>.</p>
                        <p>She receives <b data-bind="receive">₦{{ $demo['amounts'][0]['receive'] }}</b>.</p>
                    </div>
                    <p class="device__note">Nothing moves until she confirms the naira landed.</p>
                    <div class="device__actions">
                        <button type="button" data-go="sent">Confirm send</button>
                        <button type="button" class="device__ghost" data-back>Back</button>
                    </div>
                </section>

                <section class="device__panel" data-screen="sent" aria-label="Sent" hidden>
                    <div class="device__done">
                        <p class="device__done-title">Sent to Amara Okafor</p>
                        <p class="device__done-line">She receives <b data-bind="receive">₦{{ $demo['amounts'][0]['receive'] }}</b></p>
                        <p class="device__done-note">Rate locked at {{ \App\Support\RateFormatter::format($rates['GHS/NGN']) }}. She confirms, then it releases.</p>
                    </div>
                    <div class="device__actions">
                        <button type="button" data-waitlist>Join the waitlist</button>
                        <button type="button" class="device__ghost" data-restart>Run it again</button>
                    </div>
                </section>
                </div>

            </div>
        </div>
        <p class="device__disclaimer">Demo — no real money moves. The rate is today's.</p>
    </div>
@endif