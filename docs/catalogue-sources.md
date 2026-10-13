# Programme catalogue: where the additions came from

The starter catalogue (`database/seeders/data/`) was first written from general knowledge and is marked
"unverified" in the admin page. On 2026-10-13 programmes were added from three institutions' **own websites**,
so those three can be checked against a source.

| Institution | Source read | Added |
|---|---|---|
| Bindura University of Science Education (BUSE) | [Undergraduate programmes](https://www.buse.ac.zw/admissions-registration-students-records/undergraduate-programs/) | undergraduate degrees across its six faculties |
| Zimbabwe Ezekiel Guti University (ZEGU) | [All programmes](https://zegu.ac.zw/all-programs/) | undergraduate (honours) degrees and the clearest diplomas |
| Lupane State University (LSU) | [Programmes, pages 1 to 8 (92 programmes)](https://lsu.ac.zw/admissions/programmes) | undergraduate degrees and the clearest diplomas |

**Result:** 80 new programmes, and 26 existing programmes that gained one of these institutions (for example
BSc Economics is now also offered at BUSE, ZEGU and LSU). The catalogue went from 171 to 251 programmes.

## How the rows were made

- **Naming** follows the catalogue's existing style (`BSc ...`, `BCom ...`, `BEng ...`), not each university's
  long wording ("Bachelor of Science Honours Degree in ..."). In Zimbabwe an honours degree is an
  Undergraduate level.
- **Same title means the same programme.** If the catalogue already had that exact name and level, the
  institution was added to it. A different degree title is a different programme (`BSc Supply Chain Management`
  is not `BCom Supply Chain Management`), so nothing was merged on a guess.
- **No synonyms were added.** One synonym must mean exactly one programme. One new title, BUSE's "Bachelor of
  Accountancy", is already a synonym of BCom Accounting, so BUSE was added to that programme instead of
  creating a second one.
- **Checked:** every field code and institution code exists; the import into an empty database refuses no row;
  no new name collides with another programme's name or synonym.

## Not added, and why

- **Chinhoyi University of Technology (CUT):** its programme list is loaded by script and its intake notices are
  PDFs that could not be read here. Its existing programmes are unchanged.
- **Postgraduate programmes** (masters, MPhil, PhD, postgraduate diplomas) from these three institutions.
- **Titles that could not be placed in a field with confidence**, for example Disaster Management Sciences.
- **Other institutions** (UZ, NUST, MSU, GZU, AU, HIT and the polytechnics and colleges) were not re-read.
  Their rows are as before and still unverified.

## Worth a second look

The field of a programme decides what "any programme in a field" listings match. These were the judgement
calls, in case you disagree: Chemical Technology and Agricultural/Irrigation Engineering (engineering, 071),
Sport Science and Management (services, 101), Optometry (health, 091), Geography and Population Studies, Peace
and Security Studies, Industrial Psychology and Anthropology (social sciences, 031), Animal Health and
Production Extension (agriculture, 081), BBA in Police and Security Studies (security, 103), Real Estate
Management (architecture and construction, 073).

To correct any of them: Admin, Programme catalogue, Programmes, edit. Or export, fix in Excel and import
back (importing never deletes).
