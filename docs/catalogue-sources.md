# Programme catalogue: where the programmes came from

The catalogue (`database/seeders/data/`) started as a list written from general knowledge, marked
"unverified" in the admin page. It has since been extended from two sources.

## 1. The programmes workbook (the main source)

`programmes/zimbabwe_university_programmes_directory.xlsx`: 1,208 programme records from 20 universities,
compiled on 13 October 2026 from the Zimbabwe Council for Higher Education (ZIMCHE) booklet
[Accredited Universities and their Programmes](https://zimche.ac.zw/wp-content/uploads/2026/03/Accredited-Universities-and-their-Programmes-Document.pdf),
plus Zimbabwe Open University's 2026 offer list. Each record keeps its source link.

**Important:** the workbook itself says a listing in the accreditation catalogue does not prove the programme is
recruiting this intake ("Confirm current intake with university"). The ScholarZim catalogue says what exists and
where it is accredited; it does not promise an open intake.

`php programmes/build-catalogue.php` merges the workbook into `programmes.csv`. It can be re-run safely: it adds
and merges, never deletes, and a second run changes nothing. `--dry-run` only reports.

What the script does:

1. **Level.** Mapped from the workbook's award level and the programme's name: Undergraduate, Diploma,
   Certificate, Postgraduate (postgraduate and graduate diplomas), Masters (including MPhil and executive
   masters) and PhD.
2. **Name.** Rewritten in the catalogue's style, for example "Bachelor of Science (Honours) in Social Work"
   becomes "BSc Social Work". In Zimbabwe an honours degree is the Undergraduate level, so the word is dropped.
3. **Merging.** The same programme at several universities becomes one row listing all of them. A programme the
   catalogue already has, matched on its name **or one of its synonyms** at the same level, only gains the
   universities; its field and synonyms are left alone. A different degree title is a different programme (a BSc
   is not a BCom).
4. **Field.** Each new programme gets its narrow field (ISCED-F) from keywords in the subject part of its name.
   Where no keyword applies, the workbook's broad subject area supplies a default. No synonyms are added.
5. **Institutions.** The workbook's codes AJU and SU are the catalogue's ARRUPE and SOLUSI; the other 18 match.

**Result:** the catalogue went from 251 to **1,139 programmes** (618 Undergraduate, 387 Masters, 64 Diploma, 39
Postgraduate, 16 PhD, 15 Certificate). 888 are new, and 108 existing programmes gained a university.

### Check these: `programmes/needs-review.csv`

48 lines, in two kinds:

- **46 programmes whose field was only a default from the broad subject area**, with the field chosen, for example
  "Bachelor of Commerce in Fiscal Studies" (041). These are in the catalogue; check the field.
- **2 programmes not added** because no field could be chosen at all.

The field decides what "any programme in a field" scholarships match, so a wrong field matters. To correct one:
Admin, Programme catalogue, Programmes, edit. Or export, fix in Excel and import it back (importing never deletes).

## 2. Three universities' own websites (earlier additions)

Bindura University of Science Education, Zimbabwe Ezekiel Guti University and Lupane State University were read
from their own pages ([BUSE](https://www.buse.ac.zw/admissions-registration-students-records/undergraduate-programs/),
[ZEGU](https://zegu.ac.zw/all-programs/), [LSU](https://lsu.ac.zw/admissions/programmes)) before the workbook
arrived; the workbook then merged into those rows rather than duplicating them.

## What is still unverified

The programmes that were in the catalogue **before** these additions (the original starter list) and their
institution links have not been checked against a source. The workbook confirms many of them by matching, but a
row it did not match is still the starter list's word.

## Tests and the catalogue

Every seeded test loads the catalogue, and at 1,139 programmes that made the whole suite roughly fifteen times slower
(about 90 minutes instead of 6). Tests therefore default to a small copy of the original starter list
(`tests/Fixtures/catalogue/`), and the tests about the real data (`CatalogueStarterDataTest`, `CatalogueMentionsTest`,
`ScholarFitAccuracyTest`) opt in to the shipped files with `useFullCatalogue()`. Dusk and the real app use the shipped
files.

## Checks

`tests/Unit/CatalogueIntegrityTest.php` reads the CSVs and checks that no programme is listed twice under slightly
different wording, no name doubles as another programme's synonym, every institution code exists, names have no
stray "Honours" or spacing, and the large institutions are covered. `CatalogueStarterDataTest` checks that the
importer accepts every row.
