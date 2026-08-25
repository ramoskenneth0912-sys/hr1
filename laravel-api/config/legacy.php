<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy HR1 authentication bridge
    |--------------------------------------------------------------------------
    |
    | Directory holding the plain-PHP session files written by the existing
    | website (PHPSESSID cookies). Read-only: files are parsed with
    | file_get_contents and are never modified. Empty string falls back to
    | the runtime ini setting.
    |
    */

    'session_path' => env('LEGACY_SESSION_PATH', (string) ini_get('session.save_path')),

];
