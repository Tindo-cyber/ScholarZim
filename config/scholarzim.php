<?php

/**
 * Platform knobs unrelated to ScholarFit eligibility.
 */
return [

    /*
     * Optional malware scanning for uploaded documents.
     *
     * Off by default, and that default is the honest one: ScholarZim runs on a
     * single VPS with no antivirus daemon, so claiming otherwise would produce a
     * platform that reports every file as clean without having looked at one.
     * With it off, DocumentScanner marks uploads SKIPPED - a state deliberately
     * distinct from CLEAN - and the quarantine machinery around it stays live,
     * so switching a scanner on later is configuration rather than a rewrite.
     *
     * `command` receives the absolute file path as its last argument and is
     * expected to follow the clamdscan convention: exit 0 clean, exit 1 found
     * something, anything else means the scanner itself failed.
     *
     *   SCHOLARZIM_ANTIVIRUS_ENABLED=true
     *   SCHOLARZIM_ANTIVIRUS_COMMAND="clamdscan --no-summary --fdpass"
     */
    'antivirus' => [
        'enabled' => env('SCHOLARZIM_ANTIVIRUS_ENABLED', false),
        'command' => env('SCHOLARZIM_ANTIVIRUS_COMMAND', ''),
    ],

    /*
     * The bootstrap administrator the seeder creates.
     *
     * Read here rather than with env() inside the seeder, because env() only
     * works while the configuration is uncached. Once `php artisan config:cache`
     * has run - which the container entrypoint does on every production boot -
     * env() returns null and the seeder's inline defaults take over silently. An
     * operator who had set a strong SCHOLARZIM_ADMIN_PASSWORD would get the
     * published default instead, with nothing in the output to say so.
     *
     * A config file is the one place env() is guaranteed to be read, cached or
     * not, so the value survives the boot sequence either way.
     */
    'admin' => [
        'email' => env('SCHOLARZIM_ADMIN_EMAIL', 'admin@scholarzim.co.zw'),
        'password' => env('SCHOLARZIM_ADMIN_PASSWORD', 'ChangeMe123'),
    ],

    /*
     * The largest single award a listing may state before a moderator is asked
     * to look twice, per currency.
     *
     * An award above its ceiling is NOT rejected - a fully funded programme
     * abroad can legitimately cost more than any default would guess - it is
     * flagged for moderation (see AwardSanity). The point is that a typo such as
     * 50000000 for 5000 is read by a person before it is shown to applicants.
     * Currencies with no entry have no ceiling.
     */
    'award_ceilings' => [
        'USD' => 100000,
        'EUR' => 90000,
        'GBP' => 80000,
        'ZAR' => 1800000,
        'ZWG' => 2600000,
    ],

];