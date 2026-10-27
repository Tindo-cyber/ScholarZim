<?php

namespace Tests\Unit;

use App\Services\ScholarFit\DescriptionEligibility;
use Tests\TestCase;

/**
 * "medical" and "legal" are the adjectival forms of the fields "medicine" and "law" already in the phrase table, and they
 * are read the same way: only where the sentence states a target or an entry requirement, never in passing.
 */
class DescriptionEligibilityFieldTest extends TestCase
{
    /** @return array<int, string> the field of study values found in a description */
    private function fields(string $description): array
    {
        return array_values(array_map(
            fn ($c) => $c->value,
            array_filter(
                DescriptionEligibility::conditions('A Bursary', $description),
                fn ($c) => $c->kind === DescriptionEligibility::FIELD_OF_STUDY
            )
        ));
    }

    public function test_medical_adjective_triggers_medicine_field(): void
    {
        $this->assertSame(['Medicine & Health Sciences'], $this->fields('This scholarship is for medical students.'));
    }

    public function test_legal_adjective_triggers_law_field(): void
    {
        $this->assertSame(['Law'], $this->fields('Open to legal professionals in training.'));
    }

    public function test_medical_without_a_marker_does_not_trigger(): void
    {
        $this->assertSame([], $this->fields('Previous medical experience is an advantage.'));
    }

    public function test_mbbs_is_documented_as_not_a_field_match(): void
    {
        // MBBS is a programme name, not a field. Without a foreign programme catalogue this stays unmatched on purpose;
        // foreign providers are told to use other_conditions for it.
        $this->assertSame([], $this->fields('Open to MBBS students.'));
    }
}
