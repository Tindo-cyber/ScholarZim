<?php

namespace App\Services\Catalogue;

use App\Models\Field;
use App\Models\Institution;
use App\Models\Programme;
use App\Models\ProgrammeSynonym;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * The one way catalogue data gets in - from the starter files, the artisan command or an
 * administrator's upload - so all of them validate identically.
 *
 * Adds and updates, never deletes: a row missing from a file removes nothing, so a partial
 * file (one institution's programmes) is safe. A bad row is refused with its row number and
 * the reason, and the good rows around it still go in. Programmes are matched on name
 * (ignoring case) and level.
 */
class CatalogueImporter
{
    public function __construct(private readonly ?CatalogueMentions $mentions = null)
    {
    }

    public const FIELDS = 'fields';

    public const INSTITUTIONS = 'institutions';

    public const PROGRAMMES = 'programmes';

    public const MAX_ROWS = 5000;

    public const KINDS = [self::FIELDS, self::INSTITUTIONS, self::PROGRAMMES];

    /** kind => columns, in file order. The first ones are required. */
    public const COLUMNS = [
        self::FIELDS => ['code', 'name', 'parent_code', 'display_name'],
        self::INSTITUTIONS => ['code', 'name', 'type', 'province'],
        self::PROGRAMMES => ['name', 'level', 'field_code', 'institutions', 'synonyms'],
    ];

    private const REQUIRED = [
        self::FIELDS => ['code', 'name'],
        self::INSTITUTIONS => ['code', 'name', 'type'],
        self::PROGRAMMES => ['name', 'level', 'field_code'],
    ];

    private const EXTENSIONS = ['csv', 'xlsx'];

    // ----------------------------------------------------------------- input --

    /** Import a .csv or .xlsx file. */
    public function importFile(string $kind, string $path): ImportReport
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            return ImportReport::failed('only .csv and .xlsx files can be imported.');
        }

        try {
            // A CSV is read directly. The spreadsheet library is only for .xlsx: it is heavy, and
            // the starter files are loaded on every demo seed.
            $table = $extension === 'csv' ? $this->readCsv($path) : $this->readXlsx($path);
        } catch (\Throwable) {
            return ImportReport::failed('the file could not be read. Save it as .csv (UTF-8) or .xlsx and try again.');
        }

        $header = array_map(fn ($cell) => strtolower(trim((string) $cell)), array_shift($table) ?? []);
        $header = array_map(fn ($name) => ltrim($name, "\u{FEFF}"), $header);
        $rows = [];

        foreach ($table as $cells) {
            $row = [];

            foreach ($header as $i => $name) {
                if ($name !== '') {
                    $row[$name] = (string) ($cells[$i] ?? '');
                }
            }

            $rows[] = $row;
        }

        return $this->import($kind, $rows);
    }

    /** @return array<int, array<int, string|null>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException('unreadable');
        }

        $table = [];

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $table[] = $cells;
        }

        fclose($handle);

        return $table;
    }

    /** @return array<int, array<int, mixed>> */
    private function readXlsx(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $table = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
        $spreadsheet->disconnectWorksheets();

        return $table;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  keyed by column name
     */
    public function import(string $kind, array $rows): ImportReport
    {
        if (! in_array($kind, self::KINDS, true)) {
            return ImportReport::failed('unknown kind of file.');
        }

        // A file with only blank lines is not an error; there is simply nothing in it.
        $rows = array_values(array_filter($rows, fn (array $row) => collect($row)->contains(fn ($v) => trim((string) $v) !== '')));

        if (count($rows) > self::MAX_ROWS) {
            return ImportReport::failed('the file has more than ' . self::MAX_ROWS . ' rows. Split it and import the parts.');
        }

        if ($rows !== []) {
            $missing = array_values(array_diff(self::REQUIRED[$kind], array_map('strval', array_keys($rows[0]))));

            if ($missing !== []) {
                return ImportReport::failed('the file is missing the column(s): ' . implode(', ', $missing) . '. Expected: ' . implode(', ', self::COLUMNS[$kind]) . '.');
            }
        }

        $report = new ImportReport();

        DB::transaction(function () use ($kind, $rows, $report): void {
            match ($kind) {
                self::FIELDS => $this->importFields($rows, $report),
                self::INSTITUTIONS => $this->importInstitutions($rows, $report),
                self::PROGRAMMES => $this->importProgrammes($rows, $report),
            };
        });

        $this->mentions?->flush();

        return $report;
    }

    // ----------------------------------------------------------------- fields --

    private function importFields(array $rows, ImportReport $report): void
    {
        // Parents before children, whatever order the file lists them in; rows keep their
        // original number for the report.
        $order = array_keys($rows);
        usort($order, fn ($a, $b) => strlen($this->cell($rows[$a], 'code')) <=> strlen($this->cell($rows[$b], 'code')) ?: $a <=> $b);

        foreach ($order as $index) {
            $row = $rows[$index];
            $number = $index + 1;
            $code = $this->cell($row, 'code');
            $name = $this->cell($row, 'name');
            $parentCode = $this->cell($row, 'parent_code');
            $display = $this->cell($row, 'display_name');

            $problem = match (true) {
                ! preg_match('/^\d{2,4}$/', $code) => 'The code must be 2 to 4 digits (an ISCED-F code such as 07 or 071).',
                $name === '' || mb_strlen($name) > 150 => 'The name is required and must be at most 150 characters.',
                mb_strlen($display) > 100 => 'The display name must be at most 100 characters.',
                default => null,
            };

            $parent = null;

            if ($problem === null && $parentCode !== '') {
                $parent = Field::where('code', $parentCode)->first();

                $problem = match (true) {
                    $parent === null => 'The parent field ' . $parentCode . ' does not exist.',
                    ! str_starts_with($code, $parentCode) || strlen($code) <= strlen($parentCode) => 'A narrow field\'s code must start with its parent\'s (' . $parentCode . ').',
                    default => null,
                };
            }

            if ($problem !== null) {
                $report->reject($number, $problem, $this->plain($row));

                continue;
            }

            $field = Field::firstOrNew(['code' => $code]);
            $isNew = ! $field->exists;
            $field->fill(['name' => $name, 'parent_id' => $parent?->id]);

            // A blank display name in the file leaves an existing one alone: adding a column to a
            // spreadsheet must not wipe what an administrator typed on screen.
            if ($display !== '') {
                $field->display_name = $display;
            }

            $changed = $field->isDirty();
            $field->save();

            $isNew ? $report->created++ : ($changed ? $report->updated++ : null);
        }
    }

    // ----------------------------------------------------------- institutions --

    private function importInstitutions(array $rows, ImportReport $report): void
    {
        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $code = strtoupper($this->cell($row, 'code'));
            $name = $this->cell($row, 'name');
            $type = strtolower($this->cell($row, 'type'));
            $province = $this->cell($row, 'province');

            $problem = match (true) {
                ! preg_match('/^[A-Z0-9-]{1,30}$/', $code) => 'The code must be 1 to 30 letters, digits or hyphens.',
                $name === '' || mb_strlen($name) > 200 => 'The name is required and must be at most 200 characters.',
                ! in_array($type, Institution::TYPES, true) => 'The type must be one of: ' . implode(', ', Institution::TYPES) . '.',
                $province !== '' && ! in_array($province, FormOptions::ZIMBABWE_PROVINCES, true) => 'The province "' . $province . '" is not one of Zimbabwe\'s ten provinces.',
                default => null,
            };

            if ($problem !== null) {
                $report->reject($number, $problem, $this->plain($row));

                continue;
            }

            $institution = Institution::firstOrNew(['code' => $code]);
            $isNew = ! $institution->exists;
            $institution->fill(['name' => $name, 'type' => $type, 'province' => $province === '' ? null : $province]);

            if ($isNew) {
                $institution->is_active = true;
            }

            $changed = $institution->isDirty();
            $institution->save();

            $isNew ? $report->created++ : ($changed ? $report->updated++ : null);
        }
    }

    // ------------------------------------------------------------- programmes --

    /**
     * Programmes go in with a fixed number of queries, however many rows there are.
     *
     * This used to look each row up and save it on its own - about ten queries a row, so ten thousand for the
     * real catalogue. Against a database on another machine that is minutes, and the upload timed out. Now the
     * fields, institutions, existing programmes, their institution links and their synonyms are read once, every
     * row is judged in memory against them, and what is new is written in a few bulk statements at the end. The
     * rules are exactly the ones that were applied row by row: the same refusals with the same row numbers, and
     * the same programme met again later in the file is the same programme.
     */
    private function importProgrammes(array $rows, ImportReport $report): void
    {
        $fieldIds = Field::pluck('id', 'code')->all();
        $institutionIds = array_change_key_case(Institution::pluck('id', 'code')->all(), CASE_UPPER);

        // One entry per programme, existing or about to be created, keyed by lower-cased name and level.
        // $state[...]['ref'] is its database id once it has one, otherwise a temporary stand-in.
        $state = [];
        $byId = [];

        foreach (Programme::select('id', 'name', 'education_level', 'field_id', 'status')->get() as $p) {
            $key = mb_strtolower($p->name) . '|' . $p->education_level;
            $state[$key] = [
                'ref' => $p->id, 'id' => $p->id, 'name' => $p->name, 'level' => $p->education_level, 'field_id' => $p->field_id,
                'status' => $p->status, 'new' => false, 'update' => false, 'institutions' => [], 'addInstitutions' => [],
                'synonyms' => [], 'addSynonyms' => [],
            ];
            $byId[$p->id] = $key;
        }

        foreach (DB::table('institution_programme')->get(['programme_id', 'institution_id']) as $link) {
            if (isset($byId[$link->programme_id])) {
                $state[$byId[$link->programme_id]]['institutions'][$link->institution_id] = true;
            }
        }

        // Who owns each synonym, so one cannot be claimed by two programmes. Kept up to date as rows go in.
        $owners = [];

        foreach (ProgrammeSynonym::select('programme_id', 'synonym')->get() as $existing) {
            if (isset($byId[$existing->programme_id])) {
                $key = $byId[$existing->programme_id];
                $state[$key]['synonyms'][mb_strtolower($existing->synonym)] = true;
                $owners[Programme::normalise($existing->synonym)] = ['ref' => $existing->programme_id, 'name' => $state[$key]['name']];
            }
        }

        $temporary = 0;

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $name = $this->cell($row, 'name');
            $level = EducationLevel::canonical(str_replace(["'", "\u{2019}"], '', $this->cell($row, 'level')));
            $fieldId = ($code = $this->cell($row, 'field_code')) === '' ? null : ($fieldIds[$code] ?? null);
            $institutionCodes = $this->list($this->cell($row, 'institutions'), upper: true);
            $synonyms = $this->list($this->cell($row, 'synonyms'));

            $unknown = array_values(array_filter($institutionCodes, fn ($c) => ! isset($institutionIds[$c])));

            $problem = match (true) {
                $name === '' || mb_strlen($name) > 200 => 'The name is required and must be at most 200 characters.',
                ! in_array($level, Programme::LEVELS, true) => 'The level must be Certificate, Diploma, Undergraduate, Postgraduate, Masters or PhD.',
                $fieldId === null => 'The field code "' . $code . '" does not exist.',
                $unknown !== [] => 'Unknown institution code(s): ' . implode(', ', $unknown) . '.',
                collect($synonyms)->contains(fn ($s) => mb_strlen($s) > 200) => 'A synonym is longer than 200 characters.',
                default => null,
            };

            $key = $problem === null ? mb_strtolower($name) . '|' . $level : null;
            $known = $key !== null ? ($state[$key] ?? null) : null;

            // A synonym means one programme. If it already means another, the row would make a search for
            // it ambiguous, so the row is refused and the person who owns the file decides which is right.
            if ($problem === null) {
                foreach ($synonyms as $synonym) {
                    $owner = $owners[Programme::normalise($synonym)] ?? null;

                    if ($owner !== null && (string) $owner['ref'] !== (string) ($known['ref'] ?? '')) {
                        $problem = 'The synonym "' . $synonym . '" already belongs to "' . $owner['name'] . '". A synonym can belong to only one programme.';

                        break;
                    }
                }
            }

            if ($problem !== null) {
                $report->reject($number, $problem, $this->plain($row));

                continue;
            }

            $isNew = $known === null;
            $changed = false;

            if ($isNew) {
                $state[$key] = $known = [
                    'ref' => 'new:' . (++$temporary), 'id' => null, 'name' => $name, 'level' => $level, 'field_id' => $fieldId,
                    'status' => Programme::APPROVED, 'new' => true, 'update' => false, 'institutions' => [], 'addInstitutions' => [],
                    'synonyms' => [], 'addSynonyms' => [],
                ];
            } elseif ((int) $known['field_id'] !== (int) $fieldId || $known['status'] !== Programme::APPROVED) {
                $state[$key]['field_id'] = $fieldId;
                $state[$key]['status'] = Programme::APPROVED;
                $state[$key]['update'] = ! $known['new'];
                $changed = true;
            }

            foreach ($institutionCodes as $institutionCode) {
                $id = $institutionIds[$institutionCode];

                if (! isset($state[$key]['institutions'][$id]) && ! isset($state[$key]['addInstitutions'][$id])) {
                    $state[$key]['addInstitutions'][$id] = true;
                    $changed = true;
                }
            }

            foreach ($synonyms as $synonym) {
                $lower = mb_strtolower($synonym);

                if ($lower === mb_strtolower($name) || isset($state[$key]['synonyms'][$lower]) || isset($state[$key]['addSynonyms'][$lower])) {
                    continue;
                }

                $state[$key]['addSynonyms'][$lower] = $synonym;
                $owners[Programme::normalise($synonym)] = ['ref' => $state[$key]['ref'], 'name' => $state[$key]['name']];
                $changed = true;
            }

            $isNew ? $report->created++ : ($changed ? $report->updated++ : null);
        }

        $this->writeProgrammes($state);
    }

    /** The bulk writes behind importProgrammes(): new programmes, changed ones, then their institution links and synonyms. */
    private function writeProgrammes(array $state): void
    {
        $now = now();

        $new = array_filter($state, fn ($p) => $p['new']);

        foreach (array_chunk($new, 200, true) as $chunk) {
            DB::table('programmes')->insert(array_map(fn ($p) => [
                'name' => $p['name'], 'education_level' => $p['level'], 'field_id' => $p['field_id'],
                'is_active' => true, 'status' => Programme::APPROVED, 'created_at' => $now, 'updated_at' => $now,
            ], array_values($chunk)));
        }

        if ($new !== []) {
            $created = [];

            foreach (Programme::select('id', 'name', 'education_level')->get() as $p) {
                $created[mb_strtolower($p->name) . '|' . $p->education_level] = $p->id;
            }

            foreach ($new as $key => $p) {
                $state[$key]['id'] = $created[$key] ?? null;
            }
        }

        // A programme that already existed and whose field or status changed.
        foreach ($state as $p) {
            if ($p['update'] && $p['id'] !== null) {
                DB::table('programmes')->where('id', $p['id'])->update(['field_id' => $p['field_id'], 'status' => $p['status'], 'updated_at' => $now]);
            }
        }

        $links = [];
        $synonymRows = [];

        foreach ($state as $p) {
            if ($p['id'] === null) {
                continue;
            }

            foreach (array_keys($p['addInstitutions']) as $institutionId) {
                $links[] = ['institution_id' => $institutionId, 'programme_id' => $p['id']];
            }

            foreach ($p['addSynonyms'] as $synonym) {
                $synonymRows[] = ['programme_id' => $p['id'], 'synonym' => $synonym];
            }
        }

        foreach (array_chunk($links, 500) as $chunk) {
            DB::table('institution_programme')->insertOrIgnore($chunk);
        }

        foreach (array_chunk($synonymRows, 500) as $chunk) {
            DB::table('programme_synonyms')->insertOrIgnore($chunk);
        }
    }

    // ----------------------------------------------------------------- export --

    /** The catalogue as the same CSV an import reads. */
    public function exportCsv(string $kind): string
    {
        $handle = fopen('php://temp', 'w+');

        foreach ($this->exportRows($kind) as $row) {
            fputcsv($handle, array_map($this->defuse(...), $row), ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    /** The same rows as an .xlsx file's contents. */
    public function exportXlsx(string $kind): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray(
            array_map(fn ($row) => array_map($this->defuse(...), $row), $this->exportRows($kind)),
            null,
            'A1',
            true
        );

        $handle = fopen('php://temp', 'w+');
        (new XlsxWriter($spreadsheet))->save($handle);
        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return (string) $contents;
    }

    /** @return array<int, array<int, string>> header first */
    private function exportRows(string $kind): array
    {
        $rows = [self::COLUMNS[$kind]];

        if ($kind === self::FIELDS) {
            foreach (Field::with('parent')->orderBy('code')->get() as $field) {
                $rows[] = [$field->code, $field->name, $field->parent?->code ?? '', $field->display_name ?? ''];
            }
        } elseif ($kind === self::INSTITUTIONS) {
            foreach (Institution::orderBy('code')->get() as $i) {
                $rows[] = [$i->code, $i->name, $i->type, $i->province ?? ''];
            }
        } else {
            foreach (Programme::with(['field', 'institutions', 'synonyms'])->inCatalogue()->orderBy('name')->get() as $p) {
                $rows[] = [
                    $p->name,
                    $p->levelLabel(),
                    $p->field->code,
                    $p->institutions->pluck('code')->sort()->implode(';'),
                    $p->synonyms->pluck('synonym')->implode(';'),
                ];
            }
        }

        return $rows;
    }

    /** A cell a spreadsheet would run as a formula is prefixed so it is shown as text. */
    private function defuse(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    // ---------------------------------------------------------------- helpers --

    private function cell(array $row, string $key): string
    {
        $value = trim((string) ($row[$key] ?? ''));
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        // Undo the apostrophe defuse() adds, so an exported file imports back unchanged.
        return preg_match("/^'[=+\\-@]/", $value) ? substr($value, 1) : $value;
    }

    /** @return array<int, string> */
    private function list(string $value, bool $upper = false): array
    {
        $items = array_filter(array_map('trim', explode(';', $value)), fn ($item) => $item !== '');

        return array_values(array_unique($upper ? array_map('strtoupper', $items) : $items));
    }

    /** @return array<string, string> */
    private function plain(array $row): array
    {
        return array_map(fn ($v) => mb_substr((string) $v, 0, 200), $row);
    }
}
