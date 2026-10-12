# Shopee detail continuation maintenance — 0.7.36

Date: 2026-10-12. Scope: owner-requested repair of the stopped Shopee pipeline. DB schema remains 9 and canonical remains v9 / 24 columns. The broader marketplace/export roadmap remains paused.

## Cause

Batch #44 diagnostics from 0.7.35 show 89 Excel orders (79 Shopee, 10 Lazada). The provider list finished two pages with 151 unique IDs and all 79 Excel Shopee IDs matched exactly. Reconciliation then passed all 79 IDs to `get_order_details_batched()` with `max_detail_batches=1`. Its 50-ID limit rejected the request locally. Reconciliation returned `INCOMPLETE` with zero details; the pipeline advanced through an empty Payment/Income path and failed its materialization guard with `SHOPEE_RECON_INCOMPLETE_STATE` at 70%.

The exported state does not expose the caught detail exception. The rejected 79-ID call is confirmed by reproducing this input shape against the service implementation; no live provider call is needed to reproduce it.

## Repair

- Each automatic worker handles one date window and the configured number of detail chunks (one 50-ID request in the normal pipeline).
- Successfully returned details are persisted on Orders immediately. A continuation stores exact provider IDs, the next detail offset, safe counts and the completed-window baseline; provider payloads and buyer PII are excluded.
- Detail continuation reuses the completed list and requests only remaining IDs. A fingerprint binds the checkpoint to the connection, date window and ordered Excel IDs.
- The pipeline remains in reconciliation while details are pending, exposes saved detail counts and resumes them across Cron events or an explicit Resume action.
- A genuine incomplete provider response produces a warning Result from verified saved evidence, skips downstream Payment/Income and exposes the detail error. It no longer falls through to the unrelated materialization guard.
- Exact ID comparison, payment/financial formulas, canonical columns, Lazada processing, permissions and database schema are unchanged.

## Validation

`php tests/shopee-detail-continuation-check.php` runs real reconciliation and pipeline services with synthetic provider/Cron transport:

- 79 matches / 151 provider IDs / 10 Lazada orders: two list pages, details 50 + 29, interrupted explicit resume, 79 Payment operations and one 89-row materialization.
- A second date window containing 121 matches: details 50 + 50 + 21 with completed-window counts retained and exactly seven total provider calls across both windows.
- A genuine detail failure after the first 50: saved snapshots survive; a warning Result finishes without Payment/Income requests.
- Checkpoints exclude synthetic buyer markers and Lazada rows remain unchanged.

These are local synthetic checks, not live confirmation of the company's batch. Existing reconciliation, pipeline, progress, persistence, canonical and financial regression checks must also pass.

## Updating and recovering Batch #44

Deploy the complete `ecomkit-vuikhoe` folder, including `vendor/`, through the usual WordPress plugin update flow. The maintenance ZIP uses the same top-level plugin folder. No deactivate/uninstall or DB migration is required.

As Administrator, open Batch #44 under **Ecomkit → Kết quả**, expand **Công cụ quản trị nâng cao** and click **Tiếp tục xử lý sau khi kiểm tra cảnh báo**. The old `INCOMPLETE` run restarts reconciliation; detail collection now continues as 50 + 29. Completed valid Payment snapshots can still be reused through the existing pipeline behavior. Existing Excel data is retained.

Live acceptance: confirm 79/79 details, Payment counters for matched orders, 89 Result rows and no `SHOPEE_RECON_INCOMPLETE_STATE`. Lazada finance and automatic reconciliation remain outside this fix.
