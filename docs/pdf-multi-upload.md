# Multiple label PDFs — 0.7.37

Owner-requested maintenance on 2026-10-12, continuing the 0.7.36 Shopee detail repair. DB schema 9 and canonical v9 / 24 columns remain unchanged.

## Limits and workflow

- 100 MiB per PDF, 2,000 pages per PDF, up to 50 files in one selection.
- A multiple-file chooser queues one upload request at a time, so PHP handles only one temporary file and one document per request.
- Selecting more files appends to the current tab's enrichment. No forced manual upload for each individual file.
- Files rejected by size, format, template, transport or host limits receive individual messages. The queue continues and earlier successful enrichment survives.
- Exact-order duplicate labels with identical sanitized name/address are deduplicated. Conflicting values within or across files quarantine that order until Clear; clipboard uses its original Ecomkit fields.
- Clear cancels the current client request, resets counts/conflicts/errors, and ignores late responses. Copy actions are disabled while the queue is running.
- The text parser discards embedded image content to reduce memory use; barcode image decoding and OCR are not needed for the supported SPX text layer.

## Hosting boundary

The effective limit is `min(100 MiB, wp_max_upload_size())`. The UI displays this actual limit and explains a lower hosting cap. Plugin code cannot override PHP's request-level upload limit or a web server/proxy body limit. The hosting operator must allow the desired file size, enough POST space for the multipart request, and sufficient processing time/memory. HTTP 413, 504 and non-JSON failures receive specific messages without dropping prior successful files.

## Supported label evidence

Shopee SPX A6 labels remain the supported and verified layout, using exact order ID and the right-hand recipient pane. Only Shopee rows in the authorized filtered Result receive its temporary enrichment. Lazada/TikTok layouts require representative PDFs before adding their parsers; choosing an unrecognized label file reports that template limitation. The multi-file transport is ready for further parsers, but transport support is not proof of extraction support for other marketplaces.

## Validation

- PHP PDF regression checks: previous 5 MiB boundary exceeded by a valid synthetic PDF, 78-page documents pass the old page-count gate, effective lower hosting cap, over-100-MiB and over-2,000-page rejection, temporary cleanup, conflict identity filtering, nonce/capability checks, and 24-column clipboard invariants.
- `node tests/pdf-multi-upload-check.js`: executes the actual Result-page script with a synthetic DOM/transport; covers sequential selection, append, duplicate/conflict merging, individual failures, 413/non-JSON errors, selection cap, cancellation/late responses, and copy field isolation.
- `node tests/result-copy-browser-check.js`: original clipboard merge/clear regression.
- The supplied real Shopee PDF was inspected locally without committing customer contents. The original source file is preserved. Live deployment still needs normal WordPress upload/copy verification.

Only name/address are temporary clipboard overrides. No PDF content, parsed PII, phone, financial data, provider calls, canonical persistence or cron changes are introduced.
