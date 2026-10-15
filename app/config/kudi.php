<?php

namespace config;

return [

    /*
    |--------------------------------------------------------------------------
    | Launching
    |--------------------------------------------------------------------------
    |
    | Whether KudiMarket is open for business.
    |
    | True serves the public landing page and closes the trader sign-in. False serves the trader app
    | and no landing page at all. It exists as a switch rather than a deployment because the moment
    | the licence question in brand/REGULATORY.md 5b is settled is a decision, not a release, and the
    | decision needs to be reversible in seconds rather than in a code review.
    |
    | Turning it on does NOT make the product legal. It only stops the public site from inviting
    | trading before counsel has answered whether we may take the other side.
    |
    */

    'launching' => (bool) env('KUDI_LAUNCHING', false),

];
