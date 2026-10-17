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

    /*
     * The "no email?" routes on the public page.
     *
     * The landing view has always asked for a WhatsApp link and a Telegram link and the controller has
     * never once supplied them, so both rendered as href="#": two dead links, in a row that reads "No
     * email?" and offers a person nothing. On a live site that is worse than omitting the row, because
     * it looks like the link works and only fails when somebody is actually trying to reach us.
     *
     * There is deliberately no default here. A guessed wa.me number is a link to a stranger's phone, and
     * a placeholder that ships is a dead link with better branding. Until these are set the row is not
     * rendered at all, which is honest.
     *
     * The _URL suffix is the name production actually uses -- KUDI_WHATSAPP_URL and KUDI_TELEGRAM_URL
     * have been set on the server all along. Reading them without the suffix meant the page silently
     * ignored real contact details that were sitting right there in the environment.
     */
    'whatsapp' => env('KUDI_WHATSAPP_URL') ?: env('KUDI_WHATSAPP'),
    'telegram' => env('KUDI_TELEGRAM_URL') ?: env('KUDI_TELEGRAM'),

];
