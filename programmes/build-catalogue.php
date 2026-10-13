<?php
/**
 * Merge programmes/zimbabwe_university_programmes_directory.xlsx into database/seeders/data/programmes.csv.
 *
 *   php programmes/build-catalogue.php            # rewrites programmes.csv and programmes/needs-review.csv
 *   php programmes/build-catalogue.php --dry-run  # only reports
 *
 * What it does, in order:
 *  1. Reads the "Programmes" sheet (one row per programme per university).
 *  2. Maps the award level to the catalogue's level (Undergraduate, Diploma, Certificate, Postgraduate,
 *     Masters, PhD) using the sheet's level and the programme's name.
 *  3. Rewrites the name in the catalogue's style ("Bachelor of Science (Honours) in Social Work" -> "BSc Social
 *     Work"); an honours degree is Undergraduate in Zimbabwe, so the word goes.
 *  4. Merges: the same programme at several universities becomes one row; one that the catalogue already has -
 *     by name OR by one of its synonyms, same level - gains the institutions and nothing else (its field and
 *     synonyms are left alone).
 *  5. Gives a NEW programme its narrow field from its name, falling back to the sheet's broad subject area. A
 *     fallback is a weaker guess, so it is listed in needs-review.csv; a programme with no usable answer at all
 *     is NOT added and is listed there too.
 *
 * It never deletes anything and adds no synonyms.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__) . '/';
require $root . 'vendor/autoload.php';

$dry = in_array('--dry-run', $argv, true);
$dataDir = $root . 'database/seeders/data/';

// ------------------------------------------------------------------ helpers --

$readCsv = function (string $file): array {
    $h = fopen($file, 'r');
    $rows = [];
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $rows[] = $r;
    }
    fclose($h);
    return $rows;
};
$cell = static fn (string $v): string => preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
$key = static fn (string $t): string => preg_replace('/[^a-z0-9]+/', '', strtolower(str_replace('&', 'and', $t)));

// Institution codes in the workbook that differ from the catalogue's.
$institutionMap = ['AJU' => 'ARRUPE', 'SU' => 'SOLUSI'];

// ------------------------------------------------------------------ level --

$levelOf = static function (string $award, string $name): string {
    return match (true) {
        $award === 'Undergraduate degree' => 'Undergraduate',
        $award === 'Diploma' => 'Diploma',
        $award === 'Certificate' => 'Certificate',
        $award === 'Doctorate' => 'PhD',
        in_array($award, ['Postgraduate diploma', 'Graduate diploma'], true) => 'Postgraduate',
        // "Postgraduate degree" and "Other / research" are masters degrees unless the title says doctor.
        preg_match('/^doctor\b/i', $name) === 1 => 'PhD',
        default => 'Masters',
    };
};

// ------------------------------------------------------------------- name --

$cleanName = static function (string $name): string {
    $n = trim(preg_replace('/\s+/', ' ', str_replace(['&amp;', '&'], ['and', 'and'], $name)));
    // Honours is the Zimbabwean Undergraduate level, not part of the programme.
    $n = preg_replace('/\s*\((special\s+)?hono(u)?rs\)\s*/i', ' ', $n);
    $n = preg_replace('/\b(special\s+)?hono(u)?rs\s+degree\b/i', '', $n);
    $n = preg_replace('/\b(special\s+)?hono(u)?rs\b/i', '', $n);
    $n = preg_replace('/\bdegree\b/i', '', $n);
    // A bracketed abbreviation ("(LLB)", "(HBScAE)") is the same programme, not another one. "(Primary)" stays.
    $n = preg_replace('/\s*\((?:[A-Z][a-z]?){2,8}\)/', '', $n);
    $n = preg_replace('/\s+/', ' ', trim($n));
    return trim($n, " -,");
};

$styled = static function (string $name, string $level) use ($cleanName): string {
    $n = $cleanName($name);

    if ($level === 'PhD') {
        $phd = trim(preg_replace('/^Doctor of Philosophy( in)?\s*/i', 'PhD in ', $n));

        // A bare "Doctor of Philosophy" has no subject to put after "PhD in".
        return $phd === 'PhD in' || $phd === '' ? $n : $phd;
    }

    if ($level !== 'Undergraduate') {
        return $n;
    }

    // "... with Education in X" is a teaching degree in X.
    $n = preg_replace_callback('/^Bachelor of (Science|Arts|Commerce) with Education in (.+)$/i', static fn ($m) => ['science' => 'BSc', 'arts' => 'BA', 'commerce' => 'BCom'][strtolower($m[1])] . ' Education (' . trim($m[2]) . ')', $n);

    // Accounting degrees are one programme in the catalogue (its synonyms cover the other wordings).
    if (preg_match('/^Bachelor of Accountan(cy|t)$|^Bachelor of Accounting$/i', $n)) {
        return 'BCom Accounting';
    }

    $n = preg_replace('/^(Bachelor of [A-Za-z ]+?)\s+(Special\s+in|Special)\s+/i', '$1 in ', $n);

    $short = ['Science' => 'BSc', 'Commerce' => 'BCom', 'Arts' => 'BA', 'Engineering' => 'BEng', 'Technology' => 'BTech',
        'Social Sciences' => 'BSocSc', 'Social Science' => 'BSocSc', 'Agricultural Sciences' => 'BAgSc', 'Agricultural Science' => 'BAgSc'];

    foreach ($short as $long => $abbr) {
        if (preg_match('/^Bachelor of ' . preg_quote($long, '/') . '(?:\s+in\s+|\s+)(.+)$/i', $n, $m)) {
            return $abbr . ' ' . trim($m[1]);
        }
    }

    return $n;
};

// ------------------------------------------------------------------ field --

/**
 * Ordered: the first rule that matches the programme's SUBJECT (its name without the degree wording) wins.
 * [regex, narrow field code]. Education is the exception: it is judged on the whole name, because a teaching
 * degree can be "Bachelor of Education" with no subject after it.
 */
$rules = [
    // The subject is business or computing even when a word in it also suggests something else.
    ['/\b(market\w*|business (management|studies|administration|intelligence)|brand)\b/i', '041'],
    ['/information security|cyber\w*|digital forensics/i', '061'],
    ['/electronic (commerce|business)|e-?commerce|financial engineering|financial technology|fintech/i', '041'],
    ['/supply chain|procurement|purchasing|retail/i', '041'],
    ['/\b(defence|defense|military)\b/i', '103'],
    ['/monitoring and evaluation|negotiation and mediation|conflict (resolution|transformation)/i', '031'],
    ['/\b(logistics|transport\w*|aviation|maritime|shipping|aircraft)\b/i', '104'],
    ['/\blaws?\b|\blegal\b|intellectual property|\bjurisprudence\b/i', '042'],
    ['/environmental health|occupational health|health and safety|safety,? health|infection prevention|port health|sanitation/i', '102'],
    ['/veterinary|animal health/i', '084'],
    ['/social work|child protection|social welfare|child rights/i', '092'],
    ['/\b(nursing|midwifery|medicine|medical|surgery|pharmac\w*|dental|dentistry|optometry|public health|physiotherap\w*|radiograph\w*|health|clinical|epidemiolog\w*|anaesthes\w*|paediatric|biomedical|dietetics|rehabilitation|psychiatr\w*|obstetric\w*|ophthalm\w*|orthop\w*|radiolog\w*|oncolog\w*|therapeutics|therapy|counselling and community mental)\b/i', '091'],
    ['/\b(fisheries|aquaculture|aquatic)\b/i', '083'],
    ['/\bforest(ry)?\b/i', '082'],
    ['/food (science|technology|processing)|agricultural food|grain milling|food and agricultural products|value addition|\bfood\b.*\bprocessing\b/i', '072'],
    ['/\b(agricultur\w*|agri-?business|agribusiness|crop|animal|horticult\w*|soil|livestock|agronom\w*|irrigation|rangeland|dairy|agroforestry|plant breeding|food security|land reform|plant pathology)\b/i', '081'],
    ['/\b(wildlife|natural resources?|environment\w*|conservation|climate|ecology|biodiversity|water resources?|geograph\w*|earth|hydrology|disaster|sustainab\w*)\b/i', '052'],
    ['/\bhuman rights\b|\bpeace\b|international relations|public policy|\bgovernance\b|development (studies|management|programming|economics)|\bdevelopment\b/i', '031'],
    ['/telecommunication/i', '071'],
    ['/library|knowledge management|records|archives|information science/i', '032'],
    ['/\b(journalism|media|public relations|broadcast\w*|publishing|communication (science|studies)|strategic communication)\b/i', '032'],
    ['/\b(comput\w*|informatics|information (systems?|technology|communication)|software|network\w*|data science|data analytics|artificial|digital|\bict\b|information and communication)\b/i', '061'],
    ['/\b(architect\w*|construction|quantity surveying|surveying|building|civil|urban|town|regional planning|real estate|property|geomatics|geo-?informatics|housing|human settlement|land (surveying|management|administration))\b/i', '073'],
    ['/\b(mining|metallurg\w*|mineral\w*|textile|clothing|leather|wood|polymer|manufactur\w*|processing)\b/i', '072'],
    ['/\b(engineering|mechatronic\w*|industrial technology|chemical technology|applied technology|metrology|electronic\w*|electrical|mechanical|automation|photonic\w*)\b/i', '071'],
    ['/\b(actuarial)\b/i', '054'],
    ['/\b(mathematic\w*|statistic\w*|operations research)\b/i', '054'],
    ['/\b(biolog\w*|microbiolog\w*|biochem\w*|biotechnolog\w*|bioscience\w*|zoology|botany|genetic\w*|immunolog\w*|life sciences?|bioinformatics|entomology|parasitology|virology|bioengineering)\b/i', '051'],
    ['/\b(chemi\w*|physic\w*|geolog\w*|astronom\w*|material\w*|laboratory|petrol\w*|forensic|drug discovery|instrumentation)\b/i', '053'],
    ['/\b(economics?)\b/i', '031'],
    ['/\b(psycholog\w*|sociolog\w*|anthropolog\w*|politic\w*|criminolog\w*|demograph\w*|gender|counselling|social (sciences?|policy|statistics)|sustainable livelihoods|human capital)\b/i', '031'],
    ['/\b(security|defence|defense|military|police|intelligence studies|strategic studies|war studies|peacekeeping)\b/i', '103'],
    ['/\b(tourism|hospitality|hotel|culinary|catering|events?|sport\w*|recreation|fitness|beauty|hair)\b/i', '101'],
    ['/\b(accounting|accountancy|accountant|audit\w*|financ\w*|bank\w*|business|commerce|management|entrepreneur\w*|human resources?|insurance|risk|administration|leadership|project|payroll|taxation|office|secretarial|investment|strategic)\b/i', '041'],
    ['/\b(languages?|linguist\w*|english|shona|ndebele|isindebele|kalanga|tonga|venda|literary|literature|translation|interpret\w*)\b/i', '023'],
    ['/\b(history|religio\w*|theolog\w*|philosoph\w*|divinity|chaplain\w*|pastoral|ministry|heritage|archaeolog\w*|classics?|culture|catechetical|biblical|christian|church|humanities)\b/i', '022'],
    ['/\b(music|musicolog\w*|art|arts|design|fine art|film|theatre|drama|dance|performing|visual|graphic|fashion|creative|photography|multimedia)\b/i', '021'],
];

$educationRule = '/\b(education(al)?|teaching|pedagog\w*|curriculum|early childhood|special needs|teacher|inclusive)\b/i';

/** The programme's subject: its name without the degree wording in front. */
$subjectOf = static function (string $name): string {
    $s = preg_replace('/^(BSc|BCom|BA|BEng|BTech|BSocSc|BAgSc)\s+(Special\s+in\s+|Special\s+)?/i', '', $name);
    $s = preg_replace('/^(Post-Basic\s+)?(Executive\s+)?(Master|Masters|Bachelor|Doctor)(\'s)?\s+(of|in)\s+[A-Za-z]+(\s+[A-Za-z]+)?(?:\s+in\s+|\s*$)/i', '', $s);
    $s = preg_replace('/^(Executive\s+)?(Master|Masters)(\'s)?\s+in\s+/i', '', $s);
    $s = preg_replace('/^(Postgraduate|Graduate)\s+Diploma\s+in\s+|^Diploma\s+in\s+|^Certificate\s+in\s+|^Executive\s+Certificate\s+in\s+|^PhD\s+in\s+/i', '', $s);
    return trim($s) !== '' ? trim($s) : $name;
};
// Broad subject area -> the narrow field used when no rule matches. null = no safe default.
$areaDefault = [
    'Education' => '011', 'Business, Commerce & Management' => '041', 'Health & Medicine' => '091',
    'Agriculture, Environment & Natural Resources' => '081', 'Social Sciences, Development & Governance' => '031',
    'Computing, ICT & Data' => '061', 'Engineering, Built Environment & Technology' => '071', 'Law' => '042',
    'Defence, Security & Aviation' => '103', 'Natural & Applied Sciences' => '053',
    'Arts, Humanities, Media & Culture' => '022', 'Other / Interdisciplinary' => null,
];

$fieldOf = static function (string $name, string $area) use ($rules, $areaDefault, $educationRule, $subjectOf): array {
    if (preg_match($educationRule, $name)) {
        return ['011', 'rule'];
    }

    // A veterinary degree stays veterinary whatever the specialism ("Master of Veterinary Science in Epidemiology").
    if (preg_match('/veterinary/i', $name)) {
        return ['084', 'rule'];
    }

    $subject = $subjectOf($name);

    foreach ($rules as [$regex, $code]) {
        if (preg_match($regex, $subject)) {
            return [$code, 'rule'];
        }
    }
    $d = $areaDefault[$area] ?? null;
    return [$d, $d === null ? 'none' : 'area'];
};

// -------------------------------------------------------------------- run --

$wb = \PhpOffice\PhpSpreadsheet\IOFactory::load(__DIR__ . '/zimbabwe_university_programmes_directory.xlsx');
$sheet = $wb->getSheetByName('Programmes')->toArray(null, true, true, false);
array_shift($sheet);

$fieldRows = $readCsv($dataDir . 'fields.csv');
$validFields = array_column(array_slice($fieldRows, 1), 0);
$instRows = $readCsv($dataDir . 'institutions.csv');
$validInstitutions = array_column(array_slice($instRows, 1), 0);

$existing = $readCsv($dataDir . 'programmes.csv');
$header = array_shift($existing);
$rows = $existing;

// name|level and synonym|level -> row index
$index = [];
foreach ($rows as $i => $r) {
    $index[$key($r[0]) . '|' . $r[1]] = $i;
    foreach (array_filter(array_map('trim', explode(';', $r[4] ?? ''))) as $s) {
        $index[$key($s) . '|' . $r[1]] = $i;
    }
}

$stats = ['read' => 0, 'new' => 0, 'merged_existing' => 0, 'same_as_before' => 0, 'fallback_new' => 0, 'not_added' => 0, 'unknown_institution' => 0];
$review = [];
$byNew = []; // key => row index of a row this run created

foreach ($sheet as $r) {
    [$university, $acronym, , $rawName, $award, $area] = array_pad($r, 6, '');
    if (trim((string) $rawName) === '') {
        continue;
    }
    $stats['read']++;

    $code = $institutionMap[$acronym] ?? $acronym;
    if (! in_array($code, $validInstitutions, true)) {
        $stats['unknown_institution']++;
        $review[] = [$rawName, $award, $university, '', 'institution code ' . $acronym . ' is not in institutions.csv'];
        continue;
    }

    $level = $levelOf((string) $award, (string) $rawName);
    $name = $styled((string) $rawName, $level);
    $k = $key($name) . '|' . $level;

    if (isset($index[$k])) {
        $i = $index[$k];
        $have = array_filter(array_map('trim', explode(';', $rows[$i][3] ?? '')));
        if (! in_array($code, $have, true)) {
            $rows[$i][3] = implode(';', array_merge($have, [$code]));
            isset($byNew[$k]) ? null : $stats['merged_existing']++;
        } else {
            $stats['same_as_before']++;
        }
        continue;
    }

    [$field, $how] = $fieldOf($name, (string) $area);
    if ($field === null || ! in_array($field, $validFields, true)) {
        $stats['not_added']++;
        $review[] = [$rawName, $award, $university, '', 'no field could be chosen: not added'];
        continue;
    }

    if ($how === 'area') {
        $stats['fallback_new']++;
        $review[] = [$rawName, $award, $university, $field, 'field taken from the broad subject area (' . $area . '): check it'];
    }

    $rows[] = [$name, $level, $field, $code, ''];
    $index[$k] = array_key_last($rows);
    $byNew[$k] = true;
    $stats['new']++;
}

// the same name under a different case/spelling can never have got in: the index is on normalised names.
$lines = [implode(',', array_map($cell, $header))];
foreach ($rows as $r) {
    $lines[] = implode(',', array_map($cell, array_pad($r, 5, '')));
}

$reviewLines = [implode(',', ['programme_in_workbook', 'award_level', 'university', 'field_chosen', 'why'])];
foreach ($review as $r) {
    $reviewLines[] = implode(',', array_map($cell, array_map('strval', $r)));
}

echo "workbook rows read:            {$stats['read']}\n";
echo "new programmes added:          {$stats['new']}\n";
echo "  of which field from area:    {$stats['fallback_new']}\n";
echo "existing ones given a university: {$stats['merged_existing']}\n";
echo "already there, nothing to do:  {$stats['same_as_before']}\n";
echo "not added (no field):          {$stats['not_added']}\n";
echo "unknown institution:           {$stats['unknown_institution']}\n";
echo "catalogue now:                 " . count($rows) . " programmes\n";

if (! $dry) {
    file_put_contents($dataDir . 'programmes.csv', implode("\n", $lines) . "\n");
    file_put_contents(__DIR__ . '/needs-review.csv', implode("\n", $reviewLines) . "\n");
    echo "written: database/seeders/data/programmes.csv and programmes/needs-review.csv\n";
}
