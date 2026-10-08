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

    /*
     * Trust tiers: who may publish without waiting for a review.
     *
     * A provider is TRUSTED once `approved_listings_required` of their listings
     * have been approved, with no upheld student report against them and no
     * listing declined within the last `rejection_window_days` days. An
     * administrator can grant or revoke trust by hand, which wins over all of
     * this. A trusted provider's NEW listing goes live at once and lands in the
     * "published without review" queue; anything the risk checker flags still
     * gets a normal review first, trusted or not.
     */
    'trust' => [
        'approved_listings_required' => (int) env('SCHOLARZIM_TRUST_APPROVED_LISTINGS', 3),
        'rejection_window_days' => (int) env('SCHOLARZIM_TRUST_REJECTION_WINDOW_DAYS', 90),
    ],

    /*
     * Student reports of a listing.
     *
     * When `hide_after` DIFFERENT applicants have reported the same listing it is
     * taken off the public site and put back in the review queue until an
     * administrator decides; a report that is dismissed does not count towards
     * it. `per_hour` limits how many reports one account can file, so the button
     * cannot be used to harass a provider.
     */
    'reports' => [
        'hide_after' => (int) env('SCHOLARZIM_REPORTS_HIDE_AFTER', 3),
        'per_hour' => (int) env('SCHOLARZIM_REPORTS_PER_HOUR', 10),
    ],

    /*
     * Drafts: listings a provider has started and not submitted.
     *
     * `max_per_provider` is how many one provider may hold at once. Drafts are
     * private, unreviewed and free to make, so something has to stop them being a
     * place to dump data; twenty is plenty for real use. Saving a draft that
     * already exists is never blocked by this - only starting another.
     */
    'drafts' => [
        'max_per_provider' => (int) env('SCHOLARZIM_DRAFTS_MAX', 20),
    ],

    /*
     * Privacy of aggregate figures shown to providers.
     *
     * A count of matching applicants below `minimum_count` is never shown as a number
     * (not even zero), only as "fewer than N", so a small figure cannot be used to pick
     * out individual students.
     */
    'privacy' => [
        'minimum_count' => (int) env('SCHOLARZIM_MIN_COUNT', 5),
    ],

];