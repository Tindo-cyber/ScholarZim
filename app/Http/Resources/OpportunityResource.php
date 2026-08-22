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
            'country' => $this->country,
            'deadline' => $this->deadline?->toDateString(),
            'daysRemaining' => $this->daysUntilDeadline(),
            'status' => $this->statusLabel(),
            'url' => url('/scholarships/' . $this->opportunity_id),
        ];
    }
}
