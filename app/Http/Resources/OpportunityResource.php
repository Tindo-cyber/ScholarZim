<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpportunityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->opportunity_id,
            'title' => $this->title,
            'description' => $this->description,
            'awardingBody' => $this->awardingBody(),
            'educationLevel' => $this->education_level,
            'fieldOfStudy' => $this->target_field,
            'fundingType' => $this->funding_type,
            'award' => [
                'amount' => $this->award_amount !== null ? (float) $this->award_amount : null,
                'currency' => $this->award_currency,
                'formatted' => $this->formattedAward(),
                'slots' => $this->award_slots,
                'renewable' => (bool) $this->is_renewable,
            ],
            'eligibility' => [
                'minAcademicPoints' => $this->min_academic_points,
                'maxAge' => $this->max_age,
                'citizenship' => $this->required_citizenship,
                'province' => $this->required_province,
                'resultsCertificateRequired' => (bool) $this->requires_results_certificate,
            ],
            'applyUrl' => $this->external_url,
            'country' => $this->country,
            'deadline' => $this->deadline?->toDateString(),
            'daysRemaining' => $this->daysUntilDeadline(),
            'status' => $this->statusLabel(),
            'url' => url('/scholarships/' . $this->opportunity_id),
        ];
    }
}
