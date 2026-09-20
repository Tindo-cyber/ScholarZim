<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Services\SettingsService;
use App\Support\EducationLevel;
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
            // scholarfit.credit.related, not scholarfit.related_credit: the
            // latter is not a key in config/scholarfit.php, so it read null and
            // told administrators a near miss earns 0% of a dimension's weight.
            // This is the same fraction EducationMatcher and FieldMatcher award
            // for an adjacent level or a related field; nothing about what they
            // award has changed.
            'relatedCredit' => (int) round(((float) config('scholarfit.credit.related')) * 100),
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
        // A degree classification rather than the free-text results column this
        // used to set: that column is no longer read by ScholarFit, so leaving
        // it here would have shown administrators a worked example whose
        // academic dimension scored zero for no visible reason.
        $profile = new ApplicantProfile([
            'education_level' => EducationLevel::UNDERGRADUATE,
            'field_of_study' => FormOptions::FIELDS_OF_STUDY[0],
            'province' => 'Harare',
            'degree_classification' => 'Upper Second (2:1)',
            'transcript_path' => 'sample/transcript.pdf',
        ]);

        $opportunity = new Opportunity([
            'title' => 'Sample listing',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'target_field' => FormOptions::FIELDS_OF_STUDY[0],
            'deadline' => now()->addDays(10)->toDateString(),
        ]);

        $scored = $this->engine->evaluate($profile, $opportunity);

        return [
            'score' => $scored->matchScore,
            'dimensions' => $scored->breakdown->dimensions(),
        ];
    }
}
