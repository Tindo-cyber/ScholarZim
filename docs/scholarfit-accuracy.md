# ScholarFit: how it works and how its accuracy was checked

## What ScholarFit does

ScholarFit answers one question for a student and a scholarship: **does this student meet what the
scholarship says it requires?** It gives one of three answers:

| Verdict | Meaning |
|---|---|
| **Eligible** (E) | Every requirement the scholarship states is met. |
| **Not eligible** (I) | At least one stated requirement is certainly not met. |
| **Needs information** (N) | Nothing is certainly failed, but something cannot be checked yet because the profile does not say (for example no programme chosen, no A-Level results recorded, or a required document not uploaded). The student is told exactly what to add. |

Three design choices are deliberate:

- **No score.** There are no percentages, weights or "match scores". A scholarship is either open to the
  student or it is not, and the student sees why.
- **Silence is not a rule.** A requirement the provider did not state is never checked, and never shown as met.
- **A certain "no" outranks a "don't know".** If a student fails one rule outright, asking for more information
  on another would be pointless, so the verdict is "not eligible".

ScholarFit only recommends. The provider decides who is awarded, by hand, with a written reason. Gender is
recorded for the provider's information and is never read by ScholarFit (a test enforces this).

## The rules it checks

Only rules the listing actually states are checked:

- **Level of study.** Where the student is going, not where they are. An O-Level student is refused a degree
  (Undergraduate) award unless the provider ticked "O-Level holders may apply" (a student whose level still reads
  O-Level but who has recorded A-Level results counts as having finished A-Level). A recognised next step is a note
  (A-Level to Undergraduate). Only jumps nobody makes fail: a Grade 7 pupil for a diploma, or an O-Level student
  for a PhD. The full 9 by 9 level table is in `app/Services/ScholarFit/LevelJump.php`.
- **Minimum qualification, minimum A-Level points, per-subject minimum grades.**
- **Age limit**, as the student's age when applications close.
- **Province, town, rural or urban.**
- **Programme, field of study and institution.** Checked against a catalogue of programmes and institutions, so
  "BSc Computer Science" and "any programme in Computing and ICT" mean something exact. Civil Engineering is
  correctly outside the narrow field "Engineering" (071) but inside the broad field (07).
- **The level a school-leaver chose.** An O-Level or A-Level student chooses up to three programmes they hope to
  study, and those say which level they want. Someone who chose only diplomas is not matched to a degree award,
  even in the same field and even one open to all programmes; only their programmes at the award's level are
  compared with what it is open to.
- **Fields to study next.** An enrolled student or graduate can name up to three fields they want to study next
  (a Masters in Data Science after a BCom). A listing's fields are matched against these as well as against their
  own programme.
- **Conditions to check yourself.** Conditions ScholarFit has no profile field for (IELTS, work experience) are
  listed by the provider and shown as "Also check yourself". They never count as met or unmet.
- **Proof of results.** If the provider requires it, the student needs the document for their level (Grade 7
  slip, results certificate or transcript). A missing document is "needs information", because the student can
  upload it.
- **Conditions written in the title or description**, such as "open to Law students", used only where the
  provider did not fill in the matching structured field.

## Who a scholarship is open to

From 13 October 2026 a provider posting a Certificate, Diploma, degree or postgraduate award must say who it is
open to, because the programme rules above can only be exact when the provider has answered:

| Study in | The provider chooses |
|---|---|
| Zimbabwe | Specific programmes or fields, **or** "All programmes at this level" on purpose; and specific institutions **or** "Any institution". |
| Another country, "Any country" or online | Fields, **or** "All programmes at this level". The programme and institution lists hold Zimbabwean ones only, so they do not apply; host universities are written in words and shown, never checked. |

A student's own programme always belongs to a field, and fields are the international ISCED codes, so a
scholarship to study abroad matches a Zimbabwean programme through its field: a BSc Computer Science graduate
matches a Masters in Germany open to ICT. "All programmes", chosen on purpose, also outranks anything read out
of the listing's own words. Listings posted before the question was asked keep working as before (an empty
choice means anyone, and the words are read) and their provider is asked on the dashboard to answer it.

## How accuracy was checked

**1. A hand-written answer key.** Twelve made-up students (A1 to A12) were set against twenty-five made-up
scholarships (L01 to L25): 300 pairs. (It began as 11 against 21, 231 pairs; A12 and L22-L25 were added for the
"who is it open to" rules on 13 October 2026, worked out by hand before the engine was run on them.) They cover the awkward cases on purpose: a student with an almost empty
profile, one with no education level at all, one whose field was typed before the catalogue existed, a mature
Masters student, a Grade 7 pupil, and so on. The correct verdict for each pair was written down **by reasoning
from the rules, before looking at what the engine said**, with a one-line reason for the interesting ones. The
key is `tests/Fixtures/scholarfit/expected.json`.

**2. An automated test.** `ScholarFitAccuracyTest` runs the real engine on all 300 pairs and compares each with
the key. It also checks the order in which eligible scholarships are listed, and that the spreadsheets handed out
for marking match the fixtures exactly. If anyone changes who is eligible for what, this test fails and the key
has to be changed on purpose, in review, rather than behaviour drifting quietly.
**Result: the engine agrees with the key on all 300.**

**3. Independent marking.** To avoid only testing the engine against its author's own reasoning, the original 231
pairs were given as a blank spreadsheet to the project owner, who marked every cell (E, I or N) without seeing
the key or the engine's answers. Command: `php artisan scholarfit:compare <marked file>`.

**Result: 179 of 231 cells agree (77%) and 52 differ.** The differences fall into three groups, each with a
reason:

| Differences | What happened | Outcome |
|---|---|---|
| **32** | Marked "ineligible", engine says eligible. The marking treated the student's *current* level as having to match the scholarship's level (an A-Level leaver "not eligible" for an undergraduate bursary). | **Engine kept.** The agreed rule is progression: an A-Level leaver is exactly who an undergraduate bursary is for. |
| **19** | Marked "ineligible", engine says needs information. Each case is a profile that does not yet say something the scholarship asks for (programme, institution, A-Level results, education level, or an uploaded document). All 19 were checked one by one. | **Engine kept.** None is a rule the student demonstrably fails. "Ineligible" would reject a student only because their profile is incomplete. |
| **1** | A Grade 7 pupil against a Form 1 bursary with no stated requirements: marked "needs information", engine says eligible. | **Engine kept.** The pupil is exactly who it is for, and nothing in a profile could ever turn "needs information" into "eligible" here. The Grade 7 slip proves completion, and only on awards that require proof of results. |

One of the 52 arose from a rule changed *after* the first marking: a missing uploadable document used to count
as "not eligible" and is now "needs information". That moved three L20 cells from agreeing with the marking to
differing, which is consistent with the rule that "needs information" means the evidence is insufficient and
"not eligible" means a stated rule is demonstrably failed.

That marking was of the 231-pair version, before the 13 October rules. Those rules changed twelve of its cells
on purpose (A3, an O-Level student, against the degree awards), and the 69 new pairs have not been marked
independently yet: to do so, hand out the regenerated sheets in `export/1-to-mark/`.

**What this does and does not show.** It shows the engine follows one consistent, written set of rules on the
cases where rules are most likely to be wrong, and every disagreement with an independent marker has a stated
reason. It does not prove that real providers' free-text descriptions will always be read correctly: description
reading is limited to a fixed set of conditions and is only used where no structured field covers the same rule.

## Where to look in the code and tests

| What | Where |
|---|---|
| The rules | `app/Services/ScholarFit/EligibilityEvaluator.php` |
| Level table | `app/Services/ScholarFit/LevelJump.php` |
| Programme, field and institution matching | `app/Services/ScholarFit/ProgrammeScope.php` |
| Ordering of eligible scholarships (no score) | `app/Services/ScholarFit/MatchOrder.php` |
| The 300-pair test and its key | `tests/Feature/ScholarFitAccuracyTest.php`, `tests/Fixtures/scholarfit/` |
| The marking sheets and the answer key as spreadsheets | `tests/Fixtures/scholarfit/export/` |
| Comparing a marked sheet | `php artisan scholarfit:compare <file> [--brief]` |
