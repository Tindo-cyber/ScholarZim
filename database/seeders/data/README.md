# Programme catalogue - STARTER DATA, NOT VERIFIED

These three files seed the programme catalogue (fields, institutions, programmes).
They are a starting point written from general knowledge, **not** from the institutions'
own prospectuses. Treat every row as unverified until you have checked it.

Files

| File | Columns |
|---|---|
| `fields.csv` | `code`, `name`, `parent_code` - the ISCED-F 2013 broad (2-digit) and narrow (3-digit) fields. Complete. |
| `institutions.csv` | `code`, `name`, `type` (university / polytechnic / teachers_college / other), `province` (blank where unsure) |
| `programmes.csv` | `name`, `level`, `field_code` (a narrow field), `institutions` (institution codes, `;`-separated), `synonyms` (`;`-separated) |

What is most likely to be wrong or missing

- **Which institution offers which programme** (`institutions` column). Filled where I was reasonably
  sure, blank where I was not. A blank means "offered somewhere, not yet recorded", not "offered nowhere".
- Provinces left blank for a few colleges.
- Nursing schools, most vocational colleges and HEXCO-registered programmes are not here at all.
- Programme names are the common short forms; institutions word their own differently. Put the
  other wordings in `synonyms`.

How to correct it

Edit these files in Excel (Save As CSV, UTF-8) and import them from the admin catalogue screen, or
run `php artisan catalogue:import`. Importing is an upsert: it adds and updates, and never deletes.
Rows that fail validation are listed with the reason; the rest are still imported.
