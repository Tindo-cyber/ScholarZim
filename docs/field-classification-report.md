# Field-of-study catalogue: ISCED-F 2013 expansion — report

Branch `feature/isced-f-full-classification`. Not merged, not deployed. The full classification itself is in [field-classification.md](field-classification.md).

## 1. Baseline (before)

- 40 fields: 11 broad and 29 narrow. No detailed level, no supplementary codes (`…0`, `…8`, `…9`).
- Two levels were assumed in the model (`parent_id` null or not), the importer, the pickers, ScholarFit's `rankField`, and the admin screens.
- Every one of the 1,435 programmes was filed under a narrow field. Spread: 011 (177), 041 (193), 091 (123), 071 (123), 031 (118), 081 (79), 061 (72), 073 (64), 052 (64), 072 (68), 032 (54), 022 (38), 101 (36), 021 (35), 053 (32), 023 (25), 054 (23), 051 (20), 102 (18), 042 (17), 104 (17), 084 (13), 092 (8), 082 (8), 103 (6), 083 (4).
- Business and Law were already separate narrow fields; the problem was that nothing finer than a narrow field existed, and the broad heading "Business, administration and law" could be chosen as if it were one discipline.

## 2. The classification (after)

216 codes — 11 broad, 57 narrow, 148 detailed — read from the UNESCO Institute for Statistics ISCED-F 2013 manual (Appendix I) and checked against UNESCO document 37 C/53 Appendix 1. Of these, 120 are classification fields and 96 are supplementary codes (not further defined, inter-disciplinary, not elsewhere classified). `99 Field unknown` is a data-collection code, not a field of study, and is excluded.

Verified by test against a separate copy of the official list (`tests/Fixtures/isced/isced-f-2013-official.tsv`): every code present with its official name, nothing extra, correct parent for every code.

**Hierarchy versus targeting.** Every field has `level`, `kind`, `is_selectable` (may a provider/student choose it) and `is_active`. Headings that mix disciplines are not selectable (all broad fields except 01 Education and 06 ICT, which hold one discipline each). Supplementary codes are not offered as disciplines. Choosing a field includes everything beneath it.

## 3. Schema and migration

- `2025_01_01_000025`: adds `level`, `kind`, `is_selectable`, `is_active`, `synonyms` and two indexes to `fields`. Back-fills `level` from code length. No row added, moved or deleted. Reversible.
- `2025_01_01_000026`: new `programme_field_reviews` table (the review queue). Reversible.
- Loading the 216 codes is a separate, repeatable step (`php artisan catalogue:import fields`, or the admin import). Original ids 1–40 are unchanged; 176 codes were added and 26 updated on the local development database. A second run changed nothing.
- Reclassification is a third, separate step (`catalogue:reclassify`).

## 4. Reclassification of the 1,435 programmes

Rules: `app/Services/Catalogue/ProgrammeFieldRules.php`. Each rule carries the manual's wording that justifies it.

Dry run on the development database (nothing written):

| Outcome | Count |
|---|---|
| Refined to a detailed field inside the narrow field they are in (high confidence) | 1,130 |
| Queued for review (moves to another narrow field, or names joining two disciplines) | 263 |
| Stay in their narrow field (no rule recognises the name) | 42 |

Only the first group is applied by `--apply`, and each change is written to the audit log with the old and new field and the reason. A move into a different narrow field is never applied automatically, however sure the rule is. Examples queued: *BSc Spatial Planning and Management* (filed under Business, proposed 0731 Architecture and town planning), *BCom Accounting and Finance* (two disciplines), *BSc Medical Physics* (filed under Health, the manual lists medical physics under physics 0533), *BA Development Studies* (the manual does not list development studies by name). Administrators decide these at **Admin → Catalogue → Field reviews**; a dismissed proposal is not raised again.

Untouched by design: application snapshots, listings and their scopes, applicants' saved programmes. A programme the rules do not recognise stays in its narrow field, which is accurate and not a guess.

The full proposal list is produced by `php artisan catalogue:reclassify --report=path.csv`.

## 5. ScholarFit

A listing's field matches a programme's field when the programme's field is the same field or lies anywhere beneath it. Neighbouring fields never match (Finance is not Accounting; Business is not Law). A programme filed only under a broader heading is not an exact match for a more specific field. Listings saved against an old broad field keep matching everything under it. Missing information is still reported as "needs information", never silently passed; education level, grade, age and location rules are unchanged.

## 6. Components changed

Model `Field`; `FieldIndex` (new); `CatalogueImporter`; `ProgrammeScope`; `ListingScopes`; `StoreOpportunityRequest`; `ApplicantProgrammes`; admin `CatalogueController` and views (fields, programmes, programme form, old values, field reviews); provider scope picker and applicant programme card; seed data (`fields.csv`, README); new `ProgrammeReclassifier`, `ProgrammeFieldRules`, `ProgrammeFieldReview`, `catalogue:reclassify`.

## 7. Integrity

Enforced in the model (so the importer, the admin screen and scripts all hit the same rules): code is 2–4 digits and its length matches its level; a broad field has no parent; a narrow field's parent is broad, a detailed field's is narrow; a code starts with its parent's code; no self-parent or cycle; unique code (also a database constraint); a field with children, programmes or listing/applicant references cannot be deleted. The importer rejects bad rows with the reason and still loads the rest, never deletes, and is idempotent. Manipulated requests are refused server-side: heading, switched-off or invented field ids on a new choice; an existing listing keeps what it was saved with.

## 8. Behaviour changes to be aware of

- A provider can no longer newly choose a mixed broad heading such as "Engineering, manufacturing and construction"; they choose Engineering, Manufacturing or Architecture and construction (and the detailed fields beneath each). Existing listings keep theirs.
- The engineering hint on the provider form now points to the other narrow fields instead of "All of Engineering and construction".

## 9. Test results

Full PHP suite: 3,666 tests, 37,497 assertions, 0 failures, 2 skipped (pre-existing skips). New files: `FieldClassificationTest`, `HierarchicalFieldMatchingTest`, `FieldPickerHierarchyTest`, `ProgrammeReclassificationTest`, `AdminFieldHierarchyTest` (54 tests). Eight older tests that chose a mixed broad heading were updated to choose a narrow field, because that choice is now deliberately refused. Browser (Dusk) tests were not run; the picker markup and one hint sentence changed and have not been browser-tested.

## 10. Limitations

- Reclassification works from programme names only. It cannot see course content, so names the manual does not list (development studies, health information management) are proposals for a person, not decisions.
- The rule list covers the current 1,435 programmes; programmes added later start in a narrow field (or a detailed one if the person adding them chose it) until `catalogue:reclassify` is run again.
- Synonyms are search aids written for this system, not part of ISCED-F.
- Nothing has been run against the production database. Deploying means: run migrations, `catalogue:import fields`, review the dry-run report, then `catalogue:reclassify --apply` — each on its own, after backup.
