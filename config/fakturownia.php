<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API token
    |--------------------------------------------------------------------------
    |
    | Fakturownia: Account settings -> Integration -> API authorization code.
    |
    */

    'token' => env('FAKTUROWNIA_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Account domain
    |--------------------------------------------------------------------------
    |
    | The account subdomain, for example "my-company", or a complete regional
    | domain such as "my-company.fakturownia.pl".
    |
    */

    'domain' => env('FAKTUROWNIA_DOMAIN', ''),

    /*
    |--------------------------------------------------------------------------
    | Optional request defaults
    |--------------------------------------------------------------------------
    */

    'department_id' => env('FAKTUROWNIA_DEPARTMENT_ID'),
    'place' => env('FAKTUROWNIA_PLACE', ''),

];
