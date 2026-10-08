<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Honours" stops being a level anyone can choose (ScholarFit Phase 5.2).
 *
 * It meant two things. A Zimbabwean BSc / BCom Honours is a bachelor's degree, so it is
 * Undergraduate; the one-year honours after a degree, as in South Africa, is
 * Postgraduate. Both already have a home, so the third option only caused mistakes.
 *
 * Rows that already say Honours are moved to Undergraduate:
 *
 *   - applicant profiles: a student who picked Honours is almost certainly a Zimbabwean
 *     degree student;
 *   - listings (target level and minimum level): less certain - a provider may have meant
 *     the South African year - so each moved listing also gets a `level_to_confirm` risk
 *     flag, which puts it in front of an administrator (and is dropped the next time the
 *     provider saves the listing with a level they have chosen).
 *
 * Every value changed is first written to `legacy_honours_levels`, so down() can put back
 * exactly what was there - including the original spelling ('HONOURS', 'Honours Degree').
 * The flag is removed again on the way down, and nothing else is touched.
 */
return new class extends Migration
{
    /** Every spelling that has ever meant Honours, lower-cased. */
    private const SPELLINGS = ['honours', 'honours degree', 'honors', 'hons', 'bachelor honours'];

    private const FLAG = 'level_to_confirm';

    private const FLAG_MESSAGE = 'This listing was set to Honours, which is no longer a level. It has been moved to Undergraduate (a Zimbabwean BSc / BCom Honours). If it was meant for the one-year Honours after a degree, as in South Africa, it should be Postgraduate - please confirm.';

    /** table => [primary key, level columns] */
    private const TARGETS = [
        'applicant_profiles' => ['profile_id', ['education_level']],
        'opportunities' => ['opportunity_id', ['education_level', 'minimum_education_level']],
    ];

    public function up(): void
    {
        Schema::create('legacy_honours_levels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table_name', 40);
            $table->unsignedBigInteger('row_id');
            $table->string('column_name', 40);
            $table->string('original_value', 100);

            $table->unique(['table_name', 'row_id', 'column_name'], 'uk_legacy_honours');
        });

        foreach (self::TARGETS as $table => [$key, $columns]) {
            foreach ($columns as $column) {
                $rows = DB::table($table)
                    ->whereIn(DB::raw('LOWER(TRIM(' . $column . '))'), self::SPELLINGS)
                    ->get([$key, $column]);

                foreach ($rows as $row) {
                    DB::table('legacy_honours_levels')->insert([
                        'table_name' => $table,
                        'row_id' => $row->{$key},
                        'column_name' => $column,
                        'original_value' => $row->{$column},
                    ]);

                    DB::table($table)->where($key, $row->{$key})->update([$column => 'UNDERGRADUATE']);
                }
            }
        }

        $this->flagListings();
    }

    public function down(): void
    {
        foreach (DB::table('legacy_honours_levels')->get() as $saved) {
            [$key] = self::TARGETS[$saved->table_name];

            DB::table($saved->table_name)
                ->where($key, $saved->row_id)
                ->update([$saved->column_name => $saved->original_value]);
        }

        $ids = DB::table('legacy_honours_levels')->where('table_name', 'opportunities')->distinct()->pluck('row_id');

        foreach (DB::table('opportunities')->whereIn('opportunity_id', $ids)->get(['opportunity_id', 'risk_flags']) as $listing) {
            $flags = array_values(array_filter(
                (array) json_decode((string) $listing->risk_flags, true),
                fn ($flag) => ($flag['code'] ?? null) !== self::FLAG
            ));

            DB::table('opportunities')->where('opportunity_id', $listing->opportunity_id)
                ->update(['risk_flags' => $flags === [] ? null : json_encode($flags)]);
        }

        Schema::dropIfExists('legacy_honours_levels');
    }

    private function flagListings(): void
    {
        $ids = DB::table('legacy_honours_levels')->where('table_name', 'opportunities')->distinct()->pluck('row_id');

        foreach (DB::table('opportunities')->whereIn('opportunity_id', $ids)->get(['opportunity_id', 'risk_flags']) as $listing) {
            $flags = (array) json_decode((string) $listing->risk_flags, true);
            $flags[] = ['code' => self::FLAG, 'message' => self::FLAG_MESSAGE];

            DB::table('opportunities')->where('opportunity_id', $listing->opportunity_id)
                ->update(['risk_flags' => json_encode($flags)]);
        }
    }
};
