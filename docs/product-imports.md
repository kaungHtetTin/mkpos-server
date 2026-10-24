# Product CSV imports

Desktop and mobile share the authenticated, subscription-checked `module:products`
API. Ordinary API throttling remains enabled.

## Client review before upload

Selecting a CSV reads it locally; it does not submit the file. Desktop and
mobile show Checking, Passed, or Invalid status, counts, row messages, codes,
costs, stock, and selling-price previews. Local checks include UTF-8, extension,
size and row limits, CSV quoting/column structure, names, numeric values, common
selling-price columns, unit conversions, and duplicate codes inside the file.
Users can filter invalid/duplicate rows and load more preview rows without
contacting the server. Mobile uses the existing endless-list hook over local
data. A selected file and its preview live in component memory, cleared on
reset/logout; they are not saved into browser storage.

On desktop, the toolbar Import action opens the native file selector directly.
Cancelling that selector leaves Products untouched. Selecting a file opens the
modal immediately with local validation in progress; the modal contains no
file input or reselect action. Close and use Import again to review a different
file. Saved imports remain available via a footer action.

Desktop local rows use horizontal All / Invalid / Duplicate radio filters with
counts. Valid status, valid counts, and valid/ready/imported rows show a green
check icon. Preview rows, issue messages and saved imports append
automatically near the modal scroll boundary. IntersectionObserver uses the
modal body as its root; a scroll listener handles browsers without it. Failed
pages preserve existing entries and offer Retry, with stale-response and
concurrent-request guards. Changing a local filter resets the visible slice.

Desktop **Import** is disabled for local errors. A single click uploads the CSV
and immediately processes its saved import in chunks, with no second
confirmation or server-validation table. The server still checks all fields,
configured price types, and existing-product matches before creating products.
The UI shows progress and final totals. Only rejected/failed rows are fetched,
as paginated issue messages with row numbers; successful rows are not fetched.
A rejected upload keeps the local preview for retry. Interrupted processing
resumes the same saved UUID, without uploading again. Uploading a completed
file whose imported products remain active shows its existing result without
processing or refreshing inventory. If any imported products were deleted,
the explicit upload queues just those rows again and processes them normally.

Mobile retains its explicit **Upload for server validation** action and separate
Import confirmation. Both clients block invalid data; local validation is an
early check, not an authoritative database/business-rule check.

Mobile labels the upload action **Upload CSV**. Review, import/resume,
pause and completion actions share one fixed footer above workspace navigation.
Change file / Upload CSV and Change file / Import use equal-width touch targets;
Pause fills the row, and completion shows Change file / Done. The app-bar Back
arrow remains; the duplicate bottom Back to products button is removed. Footer
height is measured so long labels and translated text reserve matching scroll
space rather than covering the last review row.

## Contract

- `POST /api/product-imports`: multipart `file` (UTF-8 `.csv`, maximum 5 MB,
  5,000 product rows). Parses and validates the file without creating products.
- `GET /api/product-imports?with_total=true&limit=20&offset=0`: saved imports
  belonging to the current business.
- `GET /api/product-imports/{uuid}`: saved counts and state.
- `GET /api/product-imports/{uuid}/rows?with_total=true&limit=20&offset=0`:
  paginated row numbers, names, statuses, errors, and created product IDs.
  Optional `issues_only=true` restricts both items and total to invalid/failed
  rows, preserving row order and business isolation.
- `POST /api/product-imports/{uuid}/process`: confirms an error-free preview or
  resumes processing; writes at most 50 pending rows and returns updated counts.

The client repeats the process request until status is `completed`. This is
bounded synchronous processing, with no queue worker requirement. Leaving the
page stops subsequent requests; an already accepted chunk can still finish.
Reopen the saved import to continue. Progress survives browser/app restarts.

Each chunk locks its import record. Product creation, prices, stock movements,
and row checkpoints commit in the same database transaction. Unexpected errors
roll back the unfinished chunk; a repeated request only processes pending rows.
Row validation failures are recorded and do not create that product.

Clients automatically retry explicit HTTP 429 rejections up to three times,
waiting for `Retry-After`. Other request failures leave progress available for
manual Resume. A timeout may have occurred after commit; Resume remains safe.

## Matching and validation

The business and SHA-256 of the uploaded bytes identify an import. Uploading the
exact same file again reopens it. For a completed import, the upload locks the
saved job and checks its imported product IDs in the current business. Rows
whose products are inactive or missing return to ready and the job returns to
preview; the client automatically processes them. Active imported products keep
their checkpoints, including products without SKU/barcode. Repeated uploads
while queued/processing resume that same job without resetting its progress.
Viewing a saved result or retrying process on a completed job does not undo a
deletion; the user must explicitly upload the file again.

Re-import creates new active products and new opening stock entries. Archived
products and their historical prices/movements remain unchanged; the saved
row checkpoint links to the replacement product after processing. Files with
changed bytes are separate imports.

Non-empty SKU/barcode matches against active products are skipped; repeated
codes within the CSV are skipped too. Existing products are never updated.
Names are not identities. Products without codes in a different file may create
new products even if names match. This cannot identify products already created
by the old importer without codes.

The CSV requires a unique, non-empty heading row containing `Name`. Names,
prices, costs, stock, low-stock thresholds and unit conversions use the shared
`ProductWriter` validation used by manual product creation/editing. Malformed
values produce row errors rather than silently becoming zero. All invalid rows
must be corrected in a new file before confirmation. Optional missing numeric
fields default to zero; missing unit fields default to `Unit` / conversion 1.

Quoted commas, escaped quotes, UTF-8 BOMs, and quoted multiline names are
supported. Configured price types plus Retail/Wholesale/Credit columns are
recognized; optional ` price` suffixes and legacy unit/low-stock aliases are
accepted. Zero selling prices are preserved.

## MCP template and pre-import checks

The public `/mcp/knowledge` server exposes `get_product_import_template` and
`validate_product_import`. A support bot can supply a UTF-8 CSV template and
check the customer's actual CSV text before upload. Results include logical
row numbers, field errors, counts, duplicate codes inside the file, a content
hash and bounded pagination (50 rows by default, 100 maximum). File limits
match the app: 5 MB and 5,000 products. Unknown headings are flagged so a typo
cannot silently discard a price. Extra price names can be declared explicitly.

The checker reuses the import payload normalization and product field/unit
rules, excluding private catalog uniqueness queries. It performs no SQL,
saves no import and creates no products or stock movements. A passing public
file check is preliminary: the signed-in import still validates the business's
configuration, existing active products, subscription and permissions. This
also keeps public tools from probing another business's inventory. See the
[MCP examples and contract](mcp-customer-support.md#product-import-template-and-validation).

## Setup and verification

Run `php artisan migrate` during deployment. The additive migration creates
`product_imports` and `product_import_rows`. No queue configuration is needed.
Build/deploy both frontends with the matching backend.

Backend regression tests: `php artisan test --filter=ProductImportTest`.
These cover 237 products in five chunks, resumed/repeated processing, re-upload
identity, row errors, code matching, unit/pricing/stock preservation, business
isolation, transaction rollback after an interrupted chunk, active products
returned by the products API, and re-import after partial/all product deletion
without duplicating survivors or retrying stock movements.

Frontend interaction tests cover desktop review/upload/automatic processing,
duplicate-click prevention, mobile upload/review/confirm, invalid-row blocking,
resume after interruption, desktop pause/resume and 429 retry, history
pagination/retry, and Close/Done/Back.
The desktop source-flow test also checks that successful import refreshes the
visible products and inventory totals; closing afterward makes no extra fetch.
Mobile uses a full page at `/products/import`; saved import UUIDs can also be
opened at `/products/import/{uuid}`. The existing retained-route and
swipe-to-refresh shells remain in use. Completion reconciles affected product
lists; Back without a mutation does not explicitly reload the source.

Visual and live browser/device Back checks remain pending if the in-app browser
connection is unavailable; passing DOM tests/builds does not establish visual
acceptance.

## Control verification record

| Control | Handler and expected result | Verification |
| --- | --- | --- |
| Mobile Import CSV | Navigate to the full `/products/import` page | DOM interaction test passes; no dialog wrapper |
| Desktop Import | Open native selector once; show modal only after selection | DOM test verifies selector invocation, cancellation without a modal, immediate selected-file review, no in-modal picker and no file upload |
| Product CSV file | `checkFile`: read and validate locally, with no file POST | Desktop/mobile DOM tests assert no POST before the explicit upload/import action |
| Desktop review radios / endless scroll | Filter local rows and append within the modal scroll root | DOM test verifies observer loading once per page, All/Invalid/Duplicate switching, slice reset and no API calls; fallback scroll test passes |
| Mobile client review filter / Load more preview rows | Filter/page local rows without network requests | Mobile DOM test verifies append, retained rows, filter reset and no API calls |
| Desktop Import | `importReviewed`: upload then process all chunks in one action after local review | DOM tests verify automatic processing, one upload despite repeated clicks, no server table or successful-row requests, invalid-row blocking, rejected-upload retry, issue messages and completed-file reuse |
| Mobile Upload CSV | `uploadReviewed`: multipart upload only after successful local checks and explicit click | Mobile DOM tests verify disabled invalid-file upload, explicit valid-file upload and server revalidation |
| Mobile Import products | `run`: confirmed, bounded processing; disabled for invalid rows | Mobile DOM tests and 237-row API test pass |
| Resume import | `run`: continue the same saved import UUID | Timeout/resume DOM test and rollback/repeated-process API tests pass |
| Saved import entry | `open`: fetch summary; desktop fetches issue rows only when needed | Desktop DOM test passes; issue pagination/filtering and business isolation API tests pass |
| Load more / Retry loading | Endless-list append/retry preserves loaded entries | Mobile DOM test passes, including a failed next page |
| Desktop Saved imports | `reset`: switch to saved imports without another file selector or product write | Desktop DOM test passes |
| Mobile Change file | `reset`: return to saved imports/upload without a product write | Mobile DOM test passes |
| Desktop Close | Close dialog, retain source search and loaded inventory, restore Import focus | Source-flow DOM test verifies retained state, focus and no new inventory request |
| Mobile Done / app-bar Back | `onBack`: return to the retained source | Done test and retained-route Back test pass; source search/content retained with no new initial inventory request |
| Pause import | Abort client request/wait; server checkpoints remain resumable | Desktop DOM test verifies pause, no error alert and resume without re-upload; live click and device/browser Back remain unverified because browser tooling failed to connect |

Mouse/touch, keyboard focus, narrow/wide screenshots, and Android hardware Back
still require a working browser/device session. Existing product controls
outside the changed import flow are covered by the product-screen regressions,
not a new whole-application audit.
