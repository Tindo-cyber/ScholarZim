<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use Illuminate\Support\Carbon;

/**
 * Deadline urgency: how soon the applicant needs to act.
 *
 * Sooner scores higher, which is deliberate and worth stating because it reads
 * backwards at first glance. This dimension is not "how likely are you to win" -
 * it is a tie-breaker that surfaces the awards about to close, because a listing
 * with three days left is the one a student needs to see today. The other five
 * dimensions decide whether it is a good match at all.
 *
 * The one change from v1 is what happens with no deadline: it used to earn 80%
 * of the weight, which ranked "we never said" above an award closing in three
 * weeks. It is now the neutral half mark, on the same principle as every other
 * unstated attribute.
 */
final class DeadlineMatcher
{
    public function match(Opportunity $opportunity, int $weight): DimensionResult
    {
        $config = config('scholarfit.deadline');
        $credit = config('scholarfit.credit');

        if ($opportunity->deadline === null) {
            return DimensionResult::make(
                'deadline',
                'Deadline',
                (float) $credit['neutral'],
                $weight,
                'No closing date given'
            );
        }

        $days = (int) Carbon::today()->diffInDays($opportunity->deadline, false);

        if ($days < 0) {
            return DimensionResult::make(
                'deadline',
                'Deadline',
                0.0,
                $weight,
                'Closed ' . abs($days) . ' days ago',
                'Application deadline has passed'
            );
        }

        [$ratio, $detail] = match (true) {
            $days <= (int) $config['closing_days'] => [
                (float) $config['closing'],
                $days . ' days remaining - closing soon',
            ],
            $days <= (int) $config['soon_days'] => [
                (float) $config['soon'],
                $days . ' days remaining',
            ],
            default => [
                (float) $config['distant'],
                $days . ' days remaining',
            ],
        };

        return DimensionResult::make('deadline', 'Deadline', $ratio, $weight, $detail);
    }
}
