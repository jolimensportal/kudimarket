{{--
    What the visitor sees the instant they press the button.

    This screen is the one the research says is usually wasted. Robinhood turned it into a queue
    position and a share link, and that is the whole reason the waitlist reached a million rather
    than a thousand: a confirmation is the cheapest possible moment to turn one signup into three.

    It has to show the real number. A queue that is invented, or a position that does not move when
    you invite someone, teaches people this page is decorative -- and everything else on it is a
    promise about escrow.
--}}
<section class="confirm">
    <p class="status ok"><span class="dot"></span>You are on the list</p>

    <h1>You are number {{ session('my_position') }}.</h1>

    <p class="lead">
        Bring people and you move up. That is the whole mechanic: everyone who signs up
        with your link counts, and each one moves you a place.
    </p>

    <div class="share">
        <label for="ref">Your link</label>
        <input type="text" id="ref" class="num" readonly
               value="{{ route('landing', ['ref' => session('my_code')]) }}"
               onclick="this.select()" onfocus="this.select()">
        @if (session('my_invites') > 0)
            <p class="help"><strong>{{ session('my_invites') }} {{ Str::plural('person', session('my_invites')) }}</strong> {{ Str::plural('has', session('my_invites')) }} joined through you.</p>
        @else
            <p class="help">Nobody has used it yet.</p>
        @endif
    </div>

    <p class="foot">
        We will write once, when we open. If you would rather not hear from us at all,
        reply to that email with <strong>delete</strong> and we will remove the address.
    </p>
</section>
