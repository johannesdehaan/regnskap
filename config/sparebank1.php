<?php

/*
| SpareBank 1 personlig klient (developer.sparebank1.no).
|
| Offisielle eksempler (SpareBank1/sb1-open-api-tutorial og
| sparebank1utvikling/SB1_open_api_kotlin_example):
|   autorisasjon og token: https://api-auth.sparebank1.no/oauth
|   API:                  https://api.sparebank1.no/personal/banking
|
| Access-token varer i 10 minutter (expires_in 600). Refresh-token varer
| i 30 dager. Autorisasjonskoden kan brukes én gang og varer et par minutter.
|
| Eksemplene sender ikke OAuth-scope. Tilgangen følger API-ene som er slått
| på for klienten i portalen (Accounts og Transactions). Sett
| SPAREBANK1_SCOPE bare hvis portalen krever det. Denne appen ber ikke om
| transfer, den leser bare kontoer og transaksjoner.
*/

return [

    'client_id' => env('SPAREBANK1_CLIENT_ID'),

    'client_secret' => env('SPAREBANK1_CLIENT_SECRET'),

    'redirect_uri' => env('SPAREBANK1_REDIRECT_URI'),

    'authorize_url' => env('SPAREBANK1_AUTHORIZE_URL', 'https://api-auth.sparebank1.no/oauth/authorize'),

    'token_url' => env('SPAREBANK1_TOKEN_URL', 'https://api-auth.sparebank1.no/oauth/token'),

    'api_url' => env('SPAREBANK1_API_URL', 'https://api.sparebank1.no'),

    'scope' => env('SPAREBANK1_SCOPE'),

    'access_token_ttl' => 600,

    'refresh_token_ttl_days' => 30,

    'expiry_margin_seconds' => 60,

];
