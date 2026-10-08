{{--
    The read side of a trade: what was agreed, what happened, and where the money went.

    This is the screen a dispute is settled from and the one a regulator is shown, so it reads the
    hash-chained event record and the postings rather than anything the application believes.
--}}
<x-filament-panels::page>
    {{ $this->infolist }}
</x-filament-panels::page>
