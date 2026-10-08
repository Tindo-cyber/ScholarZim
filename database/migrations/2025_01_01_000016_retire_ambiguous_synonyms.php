<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes synonyms that were taken out of the starter catalogue as ambiguous.
 *
 * Importing adds and updates but never deletes, so a synonym dropped from programmes.csv stays in
 * every database that loaded the earlier file. These seven are named exactly - programme and
 * synonym - so nothing an administrator added is touched:
 *
 *   "IS", "SE", "CS"   two or three letters that are also ordinary words and abbreviations
 *   "BBA"              names Bachelor of Business Administration, not BCom Business Management
 *   "Journalism"       names BA Journalism and Media Studies, not BA Media and Society Studies
 *   "Motor Mechanics"  belongs to the Motor Vehicle Mechanics certificate, not the automotive diploma
 *   "Doctor of Medicine" taken off MBChB along with the others
 *
 * A programme that is not there (a catalogue never loaded) is simply skipped. down() puts each
 * synonym back where it was, again only if that programme exists.
 */
return new class extends Migration
{
    /** programme name => synonym */
    private const RETIRED = [
        'BA Media and Society Studies' => 'Journalism',
        'BCom Business Management' => 'BBA',
        'BSc Computer Science' => 'CS',
        'BSc Information Systems' => 'IS',
        'BSc Software Engineering' => 'SE',
        'Diploma in Automotive Engineering' => 'Motor Mechanics',
        'Bachelor of Medicine and Bachelor of Surgery' => 'Doctor of Medicine',
    ];

    public function up(): void
    {
        foreach (self::RETIRED as $programme => $synonym) {
            $ids = DB::table('programmes')->where('name', $programme)->pluck('id');

            DB::table('programme_synonyms')->whereIn('programme_id', $ids)->where('synonym', $synonym)->delete();
        }
    }

    public function down(): void
    {
        foreach (self::RETIRED as $programme => $synonym) {
            foreach (DB::table('programmes')->where('name', $programme)->pluck('id') as $id) {
                $exists = DB::table('programme_synonyms')->where('programme_id', $id)->where('synonym', $synonym)->exists();

                if (! $exists) {
                    DB::table('programme_synonyms')->insert(['programme_id' => $id, 'synonym' => $synonym]);
                }
            }
        }
    }
};
