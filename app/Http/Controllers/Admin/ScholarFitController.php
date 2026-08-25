<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Services\SettingsService;
use App\Support\FormOptions;
use Illuminate\Http\Request;

/**
 * Lets an administrator retune ScholarFit without a deploy.
 *
 * The page previews the effect before it is saved: a sample profile is scored
 * against a sample listing under both the current and the proposed weights, so a
 * change is never made blind.
 */
class ScholarFitController extends Controller
{
    /** Human labels for the dimension keys used in config and storage. */
    public const DIMENSION_LABELS = [
        'academic' => 'Academic record',
        'education_level' => 'Education level',
        'field' => 'Field of study',
        'location' => 'Location',
        'deadline' => 'Deadline proximity',
        'certificate' => 'Results certificate',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ScholarFitEngine $engine,
    ) {
    }

    public function index()
    {
        return view('admin.scholarfit', $this->pageData());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'weights' => ['required', 'array'],
            'weights.*' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        // The 100-point total is enforced in the service, so the API and any
        // future caller are held to the same rule as this form.
        $this->settings->updateScholarFitWeights($data['weights'], $request->user()->email);

        return redirect()
            ->route('admin.scholarfit')
            ->with('successMessage', 'ScholarFit weights updated. New scores use them immediately.');
    }

    public function reset(Request $request)
    {
        $this->settings->resetScholarFitWeights($request->user()->email);

        return redirect()
            ->route('admin.scholarfit')
            ->with('successMessage', 'ScholarFit weights reset to the shipped defaults.');
    }

    private function pageData(): array
    {
        $weights = $this->settings->scholarFitWeights();

        return [
            'weights' => $weights,
            'defaults' => config('scholarfit.weights'),
            'labels' => self::DIMENSION_LABELS,
            'isDefault' => $this->settings->scholarFitWeightsAreDefault(),
            'sample' => $this->sampleScore(),
            'relatedCredit' => (int) round(((float) config('scholarfit.related_credit')) * 100),
            'confidence' => config('scholarfit.confidence'),
        ];
    }

    /**
     * A worked example under the weights in force.
     *
     * Deliberately built in memory rather than pulled from the database: the
     * point is to show what the numbers do, and a sample that changes with the
     * seed data would make two visits to this page incomparable.
     */
    private function sampleScore(): array
    {
        $profile = new ApplicantProfile([
            'education_level' => 'Undergraduate',
            'field_of_study' => FormOptions::FIELDS_OF_STUDY[0],
            'country' => FormOptions::DEFAULT_COUNTRY,
            'province' => 'Harare',
            'academic_results' => '12 points at A-Level',
            'results_certificate_path' => 'sample/results.pdf',
        ]);

        $opportunity = new Opportunity([
            'title' => 'Sample listing',
            'education_level' => 'Undergraduate',
            'target_field' => FormOptions::FIELDS_OF_STUDY[0],
            'country' => FormOptions::DEFAULT_COUNTRY,
            'target_country' => FormOptions::DEFAULT_COUNTRY,
            'deadline' => now()->addDays(10)->toDateString(),
        ]);

        $scored = $this->engine->evaluate($profile, $opportunity);

        return [
            'score' => $scored->matchScore,
            'dimensions' => $scored->breakdown->dimensions(),
        ];
    }
}
