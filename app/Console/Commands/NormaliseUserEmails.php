<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up for accounts created before sign-up lowercased email addresses. It only reports unless --apply is
 * given, and it never merges accounts: if two accounts differ only by case it lists them and changes neither.
 */
class NormaliseUserEmails extends Command
{
    protected $signature = 'scholarzim:normalise-emails {--apply : Write the lowercase addresses (default is a report only)}';

    protected $description = 'Report, and with --apply fix, user emails stored with capital letters';

    public function handle(): int
    {
        $mixed = DB::table('users')->whereRaw('email <> LOWER(email)')->get(['user_id', 'email']);

        $clashes = DB::table('users')->select(DB::raw('LOWER(email) AS address'))
            ->groupBy('address')->havingRaw('COUNT(*) > 1')->pluck('address')->all();

        $this->info($mixed->count() . ' address(es) with capital letters; ' . count($clashes) . ' clash(es) that differ only by case.');

        foreach ($clashes as $address) {
            $this->warn("  needs a manual merge: {$address}");
        }

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $changed = 0;

        foreach ($mixed as $row) {
            if (in_array(mb_strtolower($row->email), $clashes, true)) {
                continue;
            }

            DB::table('users')->where('user_id', $row->user_id)->update(['email' => mb_strtolower($row->email)]);
            $changed++;
        }

        $this->info("Lowercased {$changed} address(es).");

        return self::SUCCESS;
    }
}
