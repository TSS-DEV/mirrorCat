# mirrorCAT — WorldCat spreadsheet matcher

A small PHP application that reproduces the core [Snapicat](https://github.com/boston-library/snapicat) workflow:

1. Upload an Excel (`.xlsx` / `.xlsm`) or CSV file.
2. Map spreadsheet columns to OCLC WorldCat indexes.
3. Search WorldCat with the OCLC Metadata API over cURL.
4. Review the retrieved brief-bib records and mark the best matches as **processed**.
5. Download the results as Excel/CSV and generate MARCXML or raw MARC (ISO 2709).

The app is self-contained: PHP + PDO SQLite, no Composer, no PhpSpreadsheet and no `ext-zip` requirement. Bootstrap and Bootstrap Icons are loaded from the jsDelivr CDN, so the browser needs an internet connection for styling and icons.

## Requirements

- PHP 8.1+ with `curl`, `pdo_sqlite`, `simplexml`, `mbstring` and `zlib` (the standard XAMPP PHP build has all of these).
- The PHP LDAP extension for directory sign-in (`extension=ldap` in `php.ini`). Not needed when LDAP sign-in is disabled or for the localhost administrator bypass.
- Apache (or another web server) serving this folder. XAMPP's default `C:\xampp\htdocs\mirrorCAT` layout is assumed.
- An OCLC WSKey with the WorldCat Metadata API scopes, **or** demo mode enabled.

## Setup

1. Put the project in the web root (for XAMPP: `C:\xampp\htdocs\mirrorCAT`) and start Apache.
2. Open <http://localhost/mirrorCAT/>. You are redirected to the sign-in page.
3. On localhost, sign in as **ADMIN** with an empty password. This account is seeded as the first administrator.
4. Set a local password for **ADMIN** on the **Users** page. Add other authorized sign-in IDs there as well. LDAP sign-in is optional and can be enabled later on **Settings**.
5. Open **Settings** and either:
   - enter your OCLC WSKey and secret, or
   - turn on **Demo mode** to try the whole workflow without credentials.
6. Create a batch by uploading a spreadsheet. A sample workbook is available from the upload page (`sample.php`).

The repository includes a clean seed database at `include/mirrorcat.db` with the schema and the `ADMIN` account, so a fresh clone starts with a working install. At runtime the file stores user accounts, OCLC credentials, uploaded spreadsheet metadata and search results. Keep credentials out of version control: if you configure real OCLC keys or add users locally, do not commit those changes to the tracked database. Runtime SQLite sidecar files (`-wal`/`-shm`) are ignored.

## Authentication

Sign-in supports two modes, chosen on **Settings → Authentication**:

1. **Local passwords** (default, SnapiCat-style) — the password is checked against a `password_hash()` hash stored in the local `users` table. LDAP and the PHP LDAP extension are not used.
2. **LDAP / Active Directory** (optional) — the password is checked against the directory, and the sign-in ID must be present and active in the local **Users** list.

Either way, the local **Users** list controls authorization. Access levels are **Administrator** (9), **Manager** (7), **Staff** (3) and **Inactive** (1). Staff can use the mirrorCAT workflow. Administrators can also open **Settings** and **Users**.

### Local passwords

Local password sign-in is enabled by default. Set or reset a user's password on the **Users** page; passwords must be at least 8 characters and are stored as one-way hashes.

To use LDAP instead, turn on **Enable LDAP / Active Directory sign-in** on the Settings page and configure the directory details below. At least one active administrator must have a local password before LDAP can be disabled again, which prevents an administrator from locking everyone out when switching back to local sign-in.

### LDAP settings

LDAP sign-in is optional and off by default. When it is enabled, the LDAP server, DNS domain, NetBIOS domain and optional base DN are stored in the `settings` table and can be edited on the Settings page. **Test saved connection** checks that the saved server is reachable; Active Directory normally rejects the anonymous test bind, which is reported as a successful connection.

The environment variables below provide the initial defaults. Once the database has been created, the saved settings take precedence:

```
MIRRORCAT_LDAP_SERVER=ldaps://ldap.example.org:636
MIRRORCAT_LDAP_DOMAIN=example.org
MIRRORCAT_LDAP_DOMAIN_NB=EXAMPLE
MIRRORCAT_LDAP_BASE_DN=
```

Replace these example values with the server, domain and NetBIOS name for your own directory.

### Localhost bypass

When the request comes from this machine through `localhost`, `127.0.0.1` or `::1`, the password check is bypassed for **active administrator** accounts. The seeded `ADMIN` administrator can sign in with only the sign-in ID. Other accounts still need a password. The bypass is never active for requests arriving from another machine.

LDAP passwords are never stored. Local passwords are stored only as `password_hash()` hashes. Sessions use an HTTP-only `SameSite=Lax` cookie and are regenerated at sign-in.

### Batch ownership

Every batch is tied to the user who uploaded it.

- Staff see only their own batches on the dashboard, the **Processed** page and in exports.
- Administrators see every batch and the owning sign-in ID.
- Direct requests for another user's batch (results, downloads, MARC generation, API details) return "not found".
- Batches created before ownership was introduced have no owner and are visible to administrators only.

### OCLC credentials

Create/obtain a WSKey at <https://platform.worldcat.org/wskey/>. The default scopes are:

```
WorldCatMetadataAPI:view_brief_bib WorldCatMetadataAPI:manage_bibs
```

The access token is cached in the `settings` table and reused until it expires. **Test saved connection** on the Settings page forces a fresh token request. Use **Clear cached token** if you change credentials.

## Workflow

### 1. Import

The first non-empty row is used as the header row. The first worksheet is imported by default; if the workbook has multiple sheets, choose another one on the batch screen and press **Re-import worksheet**. The import stops at the configured maximum row count (default 5,000).

Supported files: `.xlsx`, `.xlsm`, `.csv`, `.txt`. Legacy `.xls` files are not supported; save them as `.xlsx` first.

### 2. Map columns

Columns are auto-mapped from common header names (Title, Author, ISBN, Year, …). Review the mapping and optionally add:

- **Query template** — e.g. `ti:{Title} AND au:{Author}`. Leave it blank to build the query from the mapping. `ti:{}` uses the column mapped to `ti`.
- **Always add to every query** — e.g. `mt:book`.
- **Result order** — best match, recency, publication date, etc.

Queries are joined with `AND`. If the first mapped term uses a restricted index (year, Dewey number, call number, etc.), `kw:book` is added automatically.

### 3. Search

Searches run in small chunks from the browser (`Rows per search request`, default 10) so progress is visible and **Stop** can interrupt between chunks. Results are written to SQLite as each chunk finishes, so closing the browser does not lose completed work.

Each row stores the first brief-bib match, the OCLC number, the query used and the raw API record. Use the eye button to inspect the full record. Search errors can be retried without touching successful rows.

> The app takes the **first** record returned by WorldCat for each row, ordered by the batch's result-order setting. This mirrors Snapicat's "first match" behaviour; it is not an interactive multi-record picker.

### 4. Process

Mark found records as processed individually, with the selection checkboxes, or with **Process all found**. Processed rows are available on the **Processed** page, where they can be unprocessed or exported.

### 5. Export

- **Excel / CSV** — all columns from the uploaded spreadsheet, the paired `<column> (retrieved)` values, OCLC number, status, processed flag, search query and error. Excel values are written as text so ISBNs and leading zeros survive.
- **MARCXML** — `/manage/bibs/{oclc}` with `Accept: application/marcxml+xml`.
- **Raw MARC** — `Accept: application/marc`, with MARCXML-to-ISO-2709 conversion if the server answers with XML.

Exports are limited to `Maximum MARC records per export` (default 250) to avoid long-running requests and OCLC rate-limit problems.

## Demo mode

With demo mode on:

- Searches return deterministic generated records based on the uploaded row.
- MARCXML is generated locally, and raw MARC is converted from that MARCXML.
- No request is sent to OCLC.

Demo mode is off by default. Turn it off before working with real records.

## Rate limits

WorldCat returns an `x-ratelimit-remaining-day` response header. The app stores it and shows the remaining daily calls in the navigation bar. A `429` response pauses the search and reports the limit; successful rows remain saved.

## Project layout

| Path | Purpose |
| --- | --- |
| `login.php` / `logout.php` | LDAP or local-password sign-in, localhost bypass and sign-out |
| `users.php` | Administrator-only list of authorized sign-in IDs, levels and local passwords |
| `index.php` | Upload form, dashboard and batch list |
| `batch.php` | Mapping, search configuration, chunked search and results table |
| `processed.php` | Accepted records and export controls |
| `settings.php` | Sign-in mode, LDAP settings, OCLC credentials, demo mode and defaults (administrators) |
| `api.php` | JSON endpoints: `search`, `details`, `test` |
| `download.php` | Excel/CSV downloads |
| `marc.php` | MARCXML / raw MARC generation |
| `sample.php` | Sample workbook download |
| `include/auth.php` | LDAP and local-password authentication, localhost bypass and access checks |
| `include/db.php` | PDO connection, schema, users, batches, rows and settings |
| `include/helpers.php` | CSRF, escaping, mapping, paging and upload helpers |
| `include/oclc.php` | OAuth, cURL searches, brief-bib parsing, demo data and MARC |
| `include/xlsx.php` | Pure-PHP XLSX/CSV reader and XLSX writer |
| `include/zip.php` | Minimal ZIP reader/writer (uses zlib, not ext-zip) |
| `favicon.svg` / `favicon.ico` / `apple-touch-icon.png` | Browser tab and home-screen icons |

`include/.htaccess` denies direct web access to the database, uploads and PHP includes. The database is a local SQLite file and is **not** encrypted. LDAP passwords are never written to it; local-mode passwords are stored as `password_hash()` hashes. Use HTTPS when the app is served beyond localhost so sign-in credentials are not sent in clear text.

## Notes and limitations

- Only the first worksheet is read automatically; other sheets are available via re-import.
- One brief-bib record is stored per row (the first WorldCat match).
- CSV and XLSX are parsed in pure PHP. Very large workbooks may hit PHP's memory limit; reduce `Maximum import rows` or split the file.
- LDAP sign-in requires the PHP LDAP extension. Test the directory connection before relying on it in production; the local-password mode works without the extension.
- Local-mode passwords are managed by administrators on the Users page; there is no self-service password reset.
- The Users list must be kept current. Removing a sign-in ID does not delete their batches, it only prevents future sign-ins; an administrator can still see and remove those batches.

## Development checks

Syntax-check all PHP files:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
```

With demo mode enabled, the complete flow (upload → search → process → Excel/CSV/MARC) can be exercised without OCLC credentials.

## License

Released under the [MIT License](LICENSE).
