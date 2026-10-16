<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Indirizzo del portale
    |--------------------------------------------------------------------------
    |
    | L'indirizzo base, senza `/api/v1`: per esempio
    | `https://staging.ituoiprofessionistidigitali.it`. Senza indirizzo e
    | credenziali il sistema non è collegato e il client non parte.
    |
    */

    'url' => env('PLATFORM_URL'),

    /*
    |--------------------------------------------------------------------------
    | Credenziali del sistema
    |--------------------------------------------------------------------------
    |
    | Le rilascia l'operatore del portale e si vedono una volta sola. Il
    | segreto di firma serve a verificare i webhook che arrivano dal portale.
    |
    */

    'client_id' => env('PLATFORM_CLIENT_ID'),

    'client_secret' => env('PLATFORM_CLIENT_SECRET'),

    'signing_secret' => env('PLATFORM_SIGNING_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | Il percorso su cui il pacchetto registra la rotta che riceve gli eventi;
    | `null` per non registrarla. `reply_to_pings` risponde da solo a ogni
    | `platform.ping` di un aderente con il suo `platform.pong`, in coda.
    |
    */

    'webhook_path' => env('PLATFORM_WEBHOOK_PATH', 'platform/webhook'),

    'reply_to_pings' => (bool) env('PLATFORM_REPLY_TO_PINGS', true),

    'queue' => env('PLATFORM_QUEUE'),

    /*
    |--------------------------------------------------------------------------
    | Comportamento del client
    |--------------------------------------------------------------------------
    |
    | `validate_payloads` controlla il payload di ogni evento sullo schema del
    | catalogo prima di mandarlo; il catalogo resta in cache per
    | `catalog_ttl` secondi.
    |
    */

    'timeout' => (int) env('PLATFORM_TIMEOUT', 10),

    'retries' => (int) env('PLATFORM_RETRIES', 2),

    'validate_payloads' => (bool) env('PLATFORM_VALIDATE_PAYLOADS', true),

    'catalog_ttl' => (int) env('PLATFORM_CATALOG_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | File fra sistemi
    |--------------------------------------------------------------------------
    |
    | `file_timeout` è il tempo massimo, in secondi, per caricare o scaricare un
    | file (al massimo 25 MB). `files_disk` è il disco dove il webhook salva i
    | file ricevuti, in `platform-files/{event_id}/{file_id}`, prima di passare
    | l'evento all'applicazione.
    |
    */

    'file_timeout' => (int) env('PLATFORM_FILE_TIMEOUT', 120),

    'files_disk' => env('PLATFORM_FILES_DISK', 'local'),

];
