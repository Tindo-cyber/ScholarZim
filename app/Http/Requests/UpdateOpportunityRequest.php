<?php

namespace App\Http\Requests;

use App\Models\Opportunity;

/**
 * The same form as StoreOpportunityRequest, plus the reason an edit must give.
 *
 * The deadline rule differs, and for a reason. "Not in the past" is right for a
 * new listing, but applied to an edit it made a closed listing uneditable: its
 * deadline has passed, so any save - even fixing a typo in the description -
 * failed on a date the provider had not touched. The future-date rule now
 * applies only when the deadline is being changed.
 */
class UpdateOpportunityRequest extends StoreOpportunityRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<int, mixed> */
    protected function deadlineRules(): array
    {
        if ($this->deadlineIsUnchanged()) {
            return ['nullable', 'date'];
        }

        return parent::deadlineRules();
    }

    private function deadlineIsUnchanged(): bool
    {
        $submitted = $this->input('deadline');

        if (! filled($submitted)) {
            return false;
        }

        $current = Opportunity::query()->whereKey($this->route('id'))->value('deadline');

        if ($current === null) {
            return false;
        }

        try {
            return \Illuminate\Support\Carbon::parse($submitted)->toDateString()
                === \Illuminate\Support\Carbon::parse($current)->toDateString();
        } catch (\Throwable) {
            return false;
        }
    }
}
