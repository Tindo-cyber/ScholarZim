<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Programme;
use App\Models\ProgrammeSynonym;
use App\Services\AuditService;
use App\Services\Catalogue\CatalogueImporter;
use App\Services\Catalogue\ProgrammeCatalogue;
use App\Support\AuditAction;
use App\Support\FormOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** The administrator's screens for the programme catalogue: browse, edit, the queue, import and export. */
class CatalogueController extends Controller
{
    private const REPORT_ROWS_SHOWN = 200;

    public function __construct(
        private readonly CatalogueImporter $importer,
        private readonly ProgrammeCatalogue $catalogue,
        private readonly AuditService $audit,
    ) {
    }

    // ---------------------------------------------------------------- overview --

    public function index()
    {
        return view('admin.catalogue.index', [
            'counts' => [
                'programmes' => Programme::inCatalogue()->count(),
                'pending' => Programme::pending()->count(),
                'fields' => Field::count(),
                'institutions' => Institution::count(),
            ],
            'kinds' => CatalogueImporter::KINDS,
            'columns' => CatalogueImporter::COLUMNS,
            'report' => session('catalogueReport'),
        ]);
    }

    // -------------------------------------------------------------- programmes --

    public function programmes(Request $request)
    {
        $query = Programme::query()->with(['field.parent', 'institutions'])->withCount('synonyms');

        if (filled($request->query('q'))) {
            $like = '%' . addcslashes(trim((string) $request->query('q')), '\\%_') . '%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('synonyms', fn ($s) => $s->whereRaw("synonym LIKE ? ESCAPE '\\'", [$like])));
        }

        if (in_array($request->query('level'), Programme::LEVELS, true)) {
            $query->where('education_level', $request->query('level'));
        }

        if (filled($request->query('field'))) {
            $field = Field::find((int) $request->query('field'));
            $ids = $field ? ($field->isBroad() ? $field->children()->pluck('id')->push($field->id)->all() : [$field->id]) : [];
            $query->whereIn('field_id', $ids);
        }

        $query->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false));
        $query->when($request->query('status') === 'pending', fn ($q) => $q->where('status', Programme::PENDING));
        $query->when(! in_array($request->query('status'), ['inactive', 'pending'], true), fn ($q) => $q->where('status', Programme::APPROVED));

        return view('admin.catalogue.programmes', [
            'programmes' => $query->orderBy('name')->paginate(30)->withQueryString(),
            'levels' => Programme::LEVELS,
            'fields' => $this->fieldOptions(),
        ]);
    }

    public function createProgramme()
    {
        return view('admin.catalogue.programme-form', $this->formData(new Programme(['is_active' => true])));
    }

    public function storeProgramme(Request $request)
    {
        $data = $this->validateProgramme($request);

        $programme = DB::transaction(function () use ($data) {
            $programme = Programme::create([
                'name' => $data['name'], 'education_level' => $data['education_level'], 'field_id' => $data['field_id'],
                'is_active' => (bool) ($data['is_active'] ?? false), 'status' => Programme::APPROVED,
            ]);
            $this->syncRelations($programme, $data);

            return $programme;
        });

        $this->audit->log($request->user()->email, AuditAction::CATALOGUE_CHANGED, 'Programme', $programme->id, 'Added ' . $programme->name);

        return redirect()->route('admin.catalogue.programmes')->with('successMessage', '"' . $programme->name . '" added.');
    }

    public function editProgramme(int $id)
    {
        return view('admin.catalogue.programme-form', $this->formData(Programme::with(['synonyms', 'institutions'])->findOrFail($id)));
    }

    public function updateProgramme(Request $request, int $id)
    {
        $programme = Programme::findOrFail($id);
        $data = $this->validateProgramme($request, $programme);

        DB::transaction(function () use ($programme, $data) {
            $programme->update([
                'name' => $data['name'], 'education_level' => $data['education_level'], 'field_id' => $data['field_id'],
                'is_active' => (bool) ($data['is_active'] ?? false),
            ]);
            $this->syncRelations($programme, $data);
        });

        $this->audit->log($request->user()->email, AuditAction::CATALOGUE_CHANGED, 'Programme', $programme->id, 'Edited ' . $programme->name);

        return redirect()->route('admin.catalogue.programmes')->with('successMessage', '"' . $programme->name . '" saved.');
    }

    // ------------------------------------------------------------------ fields --

    public function fields()
    {
        // The institutions page needs these too; fields only needs `broad`.
        return view('admin.catalogue.fields', [
            'broad' => Field::whereNull('parent_id')->with(['children' => fn ($q) => $q->withCount('programmes')->orderBy('code')])->orderBy('code')->get(),
        ]);
    }

    public function storeField(Request $request)
    {
        $field = Field::create($this->validateField($request));

        $this->audit->log($request->user()->email, AuditAction::CATALOGUE_CHANGED, 'Field', $field->id, 'Added ' . $field->code . ' ' . $field->name);

        return back()->with('successMessage', 'Field ' . $field->code . ' added.');
    }

    public function updateField(Request $request, int $id)
    {
        $field = Field::findOrFail($id);
        $field->update($this->validateField($request, $field));

        $this->audit->log($request->user()->email, AuditAction::CATALOGUE_CHANGED, 'Field', $field->id, 'Edited ' . $field->code . ' ' . $field->name);

        return back()->with('successMessage', 'Field ' . $field->code . ' saved.');
    }

    // ------------------------------------------------------------ institutions --

    public function institutions()
    {
        return view('admin.catalogue.institutions', [
            'institutions' => Institution::withCount('programmes')->orderBy('type')->orderBy('name')->get(),
            'types' => Institution::TYPE_LABELS,
            'provinces' => FormOptions::ZIMBABWE_PROVINCES,
        ]);
    }

    public function storeInstitution(Request $request)
    {
        $institution = Institution::create($this->validateInstitution($request));

        $this->audit->log($request->user()->email, AuditAction::CATALOGUE_CHANGED, 'Institution', $institution->id, 'Added ' . $institution->name);

        return back()->with('successMessage', $institution->name . ' added.');
    }

    public function updateInstitution(Request $request, int $id)
    {
        $institution = Institution::findOrFail($id);
        $institution->update($this->validateInstitution($request, $institution));

        $this->audit->log($request->user()->email, AuditAction::CATALOGUE_CHANGED, 'Institution', $institution->id, 'Edited ' . $institution->name);

        return back()->with('successMessage', $institution->name . ' saved.');
    }

    // ------------------------------------------------------------------- queue --

    public function pending()
    {
        $pending = Programme::pending()->with(['field.parent', 'suggester'])->orderBy('created_at')->get();

        return view('admin.catalogue.pending', [
            'pending' => $pending,
            // Approved programmes at the same level, for "this is really that one".
            'targets' => Programme::inCatalogue()->whereIn('education_level', $pending->pluck('education_level')->unique())
                ->orderBy('name')->get(['id', 'name', 'education_level'])->groupBy('education_level'),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        return $this->decide(fn () => $this->catalogue->approve(Programme::findOrFail($id), $request->user()), 'Approved - it is in the catalogue now.');
    }

    public function merge(Request $request, int $id)
    {
        $data = $request->validate(['into' => ['required', 'integer', 'exists:programmes,id']]);

        return $this->decide(
            fn () => $this->catalogue->merge(Programme::findOrFail($id), Programme::findOrFail($data['into']), $request->user()),
            'Merged. The name is now a synonym of the existing programme.'
        );
    }

    public function reject(Request $request, int $id)
    {
        return $this->decide(fn () => $this->catalogue->reject(Programme::findOrFail($id), $request->user()), 'Rejected.');
    }

    private function decide(callable $action, string $done)
    {
        try {
            $action();
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->with('errorMessage', $e->getMessage());
        }

        return back()->with('successMessage', $done);
    }

    // --------------------------------------------------------- import / export --

    public function import(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(CatalogueImporter::KINDS)],
            'file' => ['required', 'file', 'max:2048', 'extensions:csv,xlsx'],
        ]);

        $report = $this->importer->importFile($data['kind'], $this->pathWithExtension($request));

        $this->audit->log(
            $request->user()->email,
            AuditAction::CATALOGUE_IMPORT,
            'Catalogue',
            null,
            $data['kind'] . ' (' . $request->file('file')->getClientOriginalName() . '): ' . $report->summary()
        );

        return redirect()->route('admin.catalogue')->with('catalogueReport', [
            'kind' => $data['kind'],
            'summary' => $report->summary(),
            'ok' => $report->ok(),
            'problem' => $report->fileProblem,
            'rejected' => array_slice($report->rejected, 0, self::REPORT_ROWS_SHOWN),
            'rejectedTotal' => count($report->rejected),
        ]);
    }

    public function export(string $kind, string $format): Response
    {
        abort_unless(in_array($kind, CatalogueImporter::KINDS, true) && in_array($format, ['csv', 'xlsx'], true), 404);

        $name = 'scholarzim-' . $kind . '-' . now()->format('Y-m-d') . '.' . $format;

        if ($format === 'csv') {
            // The BOM makes Excel read it as UTF-8.
            return response("\u{FEFF}" . $this->importer->exportCsv($kind), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
            ]);
        }

        return response($this->importer->exportXlsx($kind), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    /** Laravel stores an upload under a temp name with no extension; the importer decides by extension. */
    private function pathWithExtension(Request $request): string
    {
        $file = $request->file('file');
        $target = $file->getRealPath() . '.' . strtolower($file->getClientOriginalExtension());
        copy($file->getRealPath(), $target);

        register_shutdown_function(static fn () => @unlink($target));

        return $target;
    }

    // ----------------------------------------------------------------- helpers --

    /** @return array<string, mixed> */
    private function validateProgramme(Request $request, ?Programme $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'education_level' => ['required', Rule::in(Programme::LEVELS)],
            'field_id' => ['required', 'integer', 'exists:fields,id'],
            'is_active' => ['nullable', 'boolean'],
            'institutions' => ['nullable', 'array'],
            'institutions.*' => ['integer', 'exists:institutions,id'],
            'synonyms' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['name'] = trim((string) preg_replace('/\s+/', ' ', strip_tags($data['name'])));

        $clash = Programme::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->where('education_level', $data['education_level'])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();

        if ($clash) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'There is already a programme with this name at this level.']);
        }

        return $data;
    }

    private function syncRelations(Programme $programme, array $data): void
    {
        $programme->institutions()->sync($data['institutions'] ?? []);

        $lines = collect(preg_split('/\R/', (string) ($data['synonyms'] ?? '')))
            ->map(fn ($line) => trim(strip_tags((string) $line)))
            ->filter(fn ($line) => $line !== '' && mb_strtolower($line) !== mb_strtolower($programme->name))
            ->map(fn ($line) => mb_substr($line, 0, 200))
            ->unique(fn ($line) => mb_strtolower($line))
            ->take(40);

        $programme->synonyms()->delete();

        foreach ($lines as $line) {
            ProgrammeSynonym::create(['programme_id' => $programme->id, 'synonym' => $line]);
        }
    }

    /** @return array<string, mixed> */
    private function formData(Programme $programme): array
    {
        return [
            'programme' => $programme,
            'levels' => Programme::LEVELS,
            'fields' => $this->fieldOptions(),
            'institutions' => Institution::orderBy('type')->orderBy('name')->get(),
            'chosenInstitutions' => $programme->exists ? $programme->institutions->pluck('id')->all() : [],
            'synonymText' => $programme->exists ? $programme->synonyms->pluck('synonym')->implode("\n") : '',
        ];
    }

    /** @return \Illuminate\Support\Collection<int, Field> narrow fields, with their broad parent loaded */
    private function fieldOptions()
    {
        return Field::with('parent')->whereNotNull('parent_id')->orderBy('code')->get();
    }

    /** @return array<string, mixed> */
    private function validateField(Request $request, ?Field $existing = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^\d{2,4}$/', Rule::unique('fields', 'code')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', Rule::exists('fields', 'id')->whereNull('parent_id')],
        ]);

        if (filled($data['parent_id'] ?? null)) {
            $parent = Field::find($data['parent_id']);

            if (! str_starts_with($data['code'], $parent->code) || strlen($data['code']) <= strlen($parent->code)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['code' => 'A narrow field\'s code must start with its broad field\'s code (' . $parent->code . ').']);
            }
        }

        $data['name'] = trim(strip_tags($data['name']));
        $data['parent_id'] = filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function validateInstitution(Request $request, ?Institution $existing = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Za-z0-9-]{1,30}$/', Rule::unique('institutions', 'code')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(Institution::TYPES)],
            'province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'code' => strtoupper($data['code']),
            'name' => trim(strip_tags($data['name'])),
            'type' => $data['type'],
            'province' => filled($data['province'] ?? null) ? $data['province'] : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
