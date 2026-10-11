{{--
    THE ASSURANCE BOUNDARY, in one place.

    KudiMarket has two tiers and the difference between them is the entire product promise:

      CHECKED          a trader verified as who they say they are, dealing with another trader.
                       We hold the escrow and we decide disputes. WE DO NOT GUARANTEE DELIVERY.

      HANDLED          KudiMarket is the counterparty. Our money is at risk. Delivery is ours.

    The risk this component exists to remove is not a trader misunderstanding a tier. It is a trader
    reasonably reading a page that says "escrow", "checked" and "held" and concluding that somebody is
    standing behind the money -- and being wrong. That is a consumer-protection failure, not a UX bug,
    and it is much cheaper to prevent at the point of decision than to answer afterwards.

    WHY A COMPONENT AND NOT A PARAGRAPH IN EACH VIEW
    The wording already existed, in prose, on four different screens, and it drifted: the offer board
    named WHO you were dealing with ("opens a trade between you and adwoa@example.com") but never once
    said that KudiMarket does not guarantee that delivery. The screen with the most money at risk said
    the least about who stands behind it. Wording that matters this much belongs in one file so it
    cannot drift, and so a change to it is a change in one place.

    THE RULES
      - Plain words, not a badge. A badge is recognition for someone already reading; the sentence is
        for everyone, including someone who does not know what "checked" means.
      - Never colour alone. The tier is named in the text, so the meaning survives with no colour,
        in a screenshot, and in a screen reader.
      - Say the negative explicitly. "Escrow" and "checked" both sound like protection. The sentence
        has to contain the word "not".
      - Say what DOES happen instead, so the no is not read as "you are on your own with no recourse".

    $handled  bool   true when KudiMarket is the counterparty on this trade
    $counterparty       the other party's address, for the checked case. Null when it is us.
    $compact  bool    true on a list, where space is tight. The full sentence is kept -- a summary that
                      drops the guarantee is the exact failure this file is for.
--}}
@php
    $handled = $handled ?? false;
    $counterparty = $counterparty ?? null;
    $compact = $compact ?? false;
@endphp

<div class="assurance {{ $handled ? 'is-handled' : 'is-checked' }}" role="note">
    <p class="assurance-tier">
        <span class="badge {{ $handled ? 'handled' : 'checked' }}">{{ $handled ? 'KudiMarket handled' : 'Checked' }}</span>
        @unless ($compact)
            <span class="assurance-what">{{ $handled
                ? 'We are the other side.'
                : 'Another verified trader is the other side.' }}</span>
        @endunless
    </p>

    <p class="assurance-promise">
        @if ($handled)
            If the money does not arrive, it comes back to you and it is <strong>our loss</strong>,
            not a claim you have to win.
        @else
            We checked {{ $counterparty }} &mdash; identity, receiving account and their settled
            trades &mdash; and we hold both deposits while the trade runs. If they fail to deliver,
            <strong>KudiMarket decides, but settlement is not guaranteed.</strong>
        @endif
    </p>

    @unless ($compact)
        <p class="assurance-detail">
            @if ($handled)
                Nobody else is between you and this. The rate you are shown is the rate you get.
            @else
                You can raise a problem at any point and we look at both accounts before deciding.
                That is recourse, not a guarantee &mdash; we cannot make their money arrive.
            @endif
        </p>
    @endunless
</div>
