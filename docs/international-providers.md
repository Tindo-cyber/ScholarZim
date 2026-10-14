# International provider organisations

ScholarZim accepts scholarship providers registered in any country. A UK university, a South African
foundation, an international NGO or a foreign government agency registers, is verified and publishes
through **exactly the same** registration form, verification queue, provider dashboard, listing form,
moderation and application inbox as a Zimbabwean organisation. There is no separate role, dashboard,
table or workflow for foreign organisations.

## Three different questions

| Concept | Where it is stored | Who sets it | What it affects |
| --- | --- | --- | --- |
| **Country of registration** — where the organisation is legally registered | `provider_profiles.registration_country` (ISO 3166-1 alpha-2 code, e.g. `GB`) | The organisation, at registration; an administrator can record or correct it | Shown to administrators for verification. Nothing else. |
| **Study destination** — where the funded study takes place | `opportunities.country` / `target_country` (a name from `FormOptions::COUNTRIES`, or "Any country" / "Online / distance") | The provider, per listing | Listing display, search filter, and the existing study-abroad scope rules (`ListingScopes::isAbroad`) |
| **Applicant eligibility** — which students qualify | The listing's eligibility, academic and scope fields | The provider, per listing | ScholarFit |

The country of registration never pre-fills, restricts or overrides a listing's destination or its
eligibility rules. A listing with no destination still defaults to Zimbabwe, never to the provider's own
country. ScholarFit does not read the registration country at all.

Examples (all covered by `InternationalProviderPublishingTest`):

- **A** — UK foundation (`GB`) funds study in Zimbabwe: destination `Zimbabwe`.
- **B** — South African university (`ZA`) funds study in South Africa: destination `South Africa`; the
  existing study-abroad rules apply as for any provider.
- **C** — Swiss foundation (`CH`) funds study in Germany: destination `Germany`.
- **D** — US NGO funds online or flexible study: destination `Online / distance` or `Any country`.

## Registering

`/register/provider` asks for:

- **Organisation's registered name** (stored in `users.full_name`, shown to students on listings).
- **Country of registration** — required, chosen from a worldwide list (`App\Support\Countries`, the 249
  ISO 3166-1 codes). Nothing is pre-selected; the list has a type-to-filter box when JavaScript is on, and
  the plain `<select>` works without it. The server accepts only a code from that list.
- **Organisation type** (unchanged).
- **Official registration number**, in any format the country uses (letters, digits and `. / - ( ) # : & ,`),
  **or**, if the organisation has none (for example a body established by statute), an **explanation**.
  One of the two is required. No country-specific format is enforced.
- **Registration certificate or equivalent document** — same rules as before: PDF, Word, JPG or PNG, up
  to 5 MB, content-type checked by `FileStorageService`, stored privately.
- **Website** (optional, normalised and validated by `WebsiteAddress`, unconfirmed until an admin confirms it).
- **Contact person's name and position**, **work email** (must be verified as before), and an optional
  **phone** validated by `InternationalPhoneNumber` (any country; numbers without a country code are read
  as Zimbabwean).
- The existing confirmation that the person is authorised to act for the organisation.

Every new provider starts `PENDING`, whatever its country. The country is a statement by the organisation,
not evidence: it is checked against the uploaded document like everything else.

## Verification

Administrators see pending organisations in the existing queue on `/admin/dashboard` (now with a
**Registered in** column and the registration number) and open **Review** for the full record at
`/admin/providers/{id}`: name, country (with its code), type, registration number or explanation, the
document (opened through the existing audited route), website and its confirmation, contact person,
position, email, phone, email-verification state, submission date and review history.

Four states, derived from existing fields (`App\Support\ProviderVerificationStatus`):

| State | Meaning | Can publish? |
| --- | --- | --- |
| Awaiting review | Registered, or has answered a request for information | No |
| More information requested | An admin asked for more evidence; waiting on the organisation | No |
| Verified | Approved (`reviewed_at` set, account `ACTIVE`) | Yes, under the usual moderation and trust rules |
| Rejected | Refused with a reason | No |

Actions:

- **Approve / Reject** — the existing routes and service, unchanged except that the audit entry now names
  the country, and that an organisation with **no country on record** cannot be approved until an
  administrator records it (an unknown country is never assumed).
- **Request more information** — the account stays `PENDING`; the organisation is notified
  (`PROVIDER_INFO_REQUESTED`), sees the note on its dashboard and can update its registration details,
  contact and document there. Doing so returns it to *Awaiting review* and notifies administrators. It
  never approves anything.
- **Registration details** — an administrator can record or correct country, number and explanation for
  any provider. It does not change the account's status, listings or applications, and is audited with
  old and new values (`UPDATE_PROVIDER_REGISTRATION`).

Once verified, an organisation cannot change its checked registration details itself.

## Accounts registered before this change

Migration `2025_01_01_000020_add_registration_details_to_provider_profiles` only adds nullable columns.
No existing row is rewritten and **no country is backfilled** — assuming Zimbabwe would put an unverified
statement on record. For such accounts:

- They keep working exactly as before. A verified provider keeps publishing; nothing asks them to
  re-register.
- Admin screens show *Not recorded*; the provider's dashboard tells them an administrator will record it.
- An administrator records the country from the review page, using the document already on file.
- A legacy account still *pending* cannot be approved until its country is recorded.
- Any `registration_number` stored before the field was retired in `2025_01_01_000008` is kept and shown.

## Testing it

Automated:

```bash
php artisan test --filter 'InternationalProvider|ProviderRegistrationDetailsMigration|CountriesTest|RegistrationFieldsTest|ProviderVerificationTest'
```

Manually (with the demo seed): register at `/register/provider` choosing e.g. *United Kingdom*; sign in as
`admin@scholarzim.co.zw`, open the organisation from **Providers awaiting verification**, open the document,
try **Request more information**, update details as the organisation, then approve; sign in as the
organisation and post listings for Zimbabwe, Germany and *Online / distance*. See
`docs/manual-qa-checklist.md`.
