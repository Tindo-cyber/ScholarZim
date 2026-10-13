# Programme catalogue: where the programmes came from

The catalogue (`database/seeders/data/`) started as a list written from general knowledge, marked
"unverified" in the admin page. It has since been extended from three sources.

## 1. The university programmes workbook

`programmes/zimbabwe_university_programmes_directory.xlsx`: 1,208 programme records from 20 universities,
compiled on 13 October 2026 from the Zimbabwe Council for Higher Education (ZIMCHE) booklet
[Accredited Universities and their Programmes](https://zimche.ac.zw/wp-content/uploads/2026/03/Accredited-Universities-and-their-Programmes-Document.pdf),
plus Zimbabwe Open University's 2026 offer list. Each record keeps its source link.

**Important:** the workbook itself says a listing in the accreditation catalogue does not prove the programme is
recruiting this intake ("Confirm current intake with university"). The ScholarZim catalogue says what exists and
where it is accredited; it does not promise an open intake.

`php programmes/build-catalogue.php` merges both workbooks into `programmes.csv`. It can be re-run safely: it adds
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

**Result of this workbook:** the catalogue went from 251 to 1,139 programmes. 888 were new and 108 existing
programmes gained a university.

## 2. The polytechnic programmes workbook

`programmes/zimbabwe_polytechnic_programmes_directory.xlsx`: 345 course records from the eight polytechnic colleges
the Ministry of Higher and Tertiary Education lists (Bulawayo, Gweru, Harare, Joshua Mqabuko Nkomo, Kwekwe with its
Gokwe campus, Kushinga Phikelela, Masvingo and Mutare), compiled on 13 October 2026 from the colleges' own course
pages and intake adverts, with a source link on every row. Its own notes say a course on a college page may not
be on the current intake, and that coverage of Mutare is partial.

`build-catalogue.php` reads it as well. A course there lists the awards it leads to in one cell ("NC; ND; HND"),
and each award is its own programme:

| Workbook | Catalogue level | Name |
|---|---|---|
| NC (National Certificate) | Certificate | National Certificate in X |
| ND (National Diploma) | Diploma | Diploma in X |
| HND (Higher National Diploma) | Diploma | Higher National Diploma in X |
| B-Tech | Undergraduate | BTech X |

Names are tidied ("&" to "and", bracketed abbreviations such as "(FETC)" dropped). A National Certificate or
Diploma that the catalogue already had under a slightly different name ("Certificate in X") is matched, not
duplicated. The Gokwe campus counts as Kwekwe Polytechnic. Fields come from keywords in the course name (trade and
technical subjects have their own rules), then from the college division's name.

**Not added:** 21 rows whose award level is not stated ("Short course", "Skills Development", "confirm award
level"), because a programme with no level cannot go in the catalogue. They are listed in the review file.

**Result of this workbook:** 297 new programmes; the other courses were merged into rows that already existed
or were created earlier in the same run (the same course at several colleges is one row).

**The catalogue now holds 1,435 programmes:** 621 Undergraduate, 387 Masters, 228 Diploma, 144 Certificate, 39
Postgraduate and 16 PhD. Polytechnics: Harare 159, Bulawayo 148, Masvingo 69, Kushinga Phikelela 65, Kwekwe 64,
Gweru 62, Joshua Mqabuko Nkomo 48, Mutare 15.

### Check these: `programmes/needs-review.csv`

83 lines, for both workbooks:

- **53 programmes whose field was only a default** (from the university workbook's broad subject area, or the
  polytechnic college's division). They are in the catalogue; check the field.
- **21 polytechnic rows not added** (award level not stated) and **2 university rows not added** (no field could
  be chosen).
- **6 polytechnic courses** where the workbook says the awards (the ND or HND) need confirming; the awards it
  states were added.
- **1 note** that a course names its own award, so no Higher National version was made.

The field decides what "any programme in a field" scholarships match, so a wrong field matters. To correct one:
Admin, Programme catalogue, Programmes, edit. Or export, fix in Excel and import it back (importing never deletes).
The review file accumulates across runs, so running the loader again never loses an earlier flag.

## 3. Three universities' own websites (earlier additions)

Bindura University of Science Education, Zimbabwe Ezekiel Guti University and Lupane State University were read
from their own pages ([BUSE](https://www.buse.ac.zw/admissions-registration-students-records/undergraduate-programs/),
[ZEGU](https://zegu.ac.zw/all-programs/), [LSU](https://lsu.ac.zw/admissions/programmes)) before the workbook
arrived; the workbook then merged into those rows rather than duplicating them.

## What is still unverified

The programmes that were in the catalogue **before** these additions (the original starter list) and their
institution links have not been checked against a source. The workbook confirms many of them by matching, but a
row it did not match is still the starter list's word.

## Tests and the catalogue

Every seeded test loads the catalogue, and at over a thousand programmes that made the whole suite roughly fifteen times slower
(about 90 minutes instead of 6). Tests therefore default to a small copy of the original starter list
(`tests/Fixtures/catalogue/`), and the tests about the real data (`CatalogueStarterDataTest`, `CatalogueMentionsTest`,
`ScholarFitAccuracyTest`) opt in to the shipped files with `useFullCatalogue()`. Dusk and the real app use the shipped
files.

## Checks

`tests/Unit/CatalogueIntegrityTest.php` reads the CSVs and checks that no programme is listed twice under slightly
different wording, no name doubles as another programme's synonym, every institution code exists, names have no
stray "Honours" or spacing, and the large institutions are covered. `CatalogueStarterDataTest` checks that the
importer accepts every row.
