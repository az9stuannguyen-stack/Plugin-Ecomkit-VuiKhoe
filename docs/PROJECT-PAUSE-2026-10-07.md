# Ecomkit Vui Khỏe — Project Pause & Resume Notes

Date: **2026-10-07**

Status: **PAUSED**

> Không tiếp tục code plugin cho đến khi có quyết định mở lại dự án.
> Khi quay lại, đọc toàn bộ tài liệu này trước khi sửa code.

## Purpose and evidence boundary

Ecomkit is an internal WordPress plugin for uploading marketplace Excel orders, normalizing them into a shared workflow, reconciling exact marketplace IDs, retrieving provider evidence and producing a shared canonical Result for accounting/operations. It supports Shopee, Lazada and eventually TikTok Shop. Intended normal workflow: **Upload ONE Excel → wait → Result → copy/export**, without requiring users to understand provider APIs.

The owner intentionally paused development to do other work. Shopee production is operational; Lazada awaits Sensitive Data Privilege / Unmask approval; TikTok requires further business/API onboarding and currently has low order volume. WP.7 and other enhancements wait for the marketplace roadmap to resume.

Live results below are the project owner's reported historical evidence, not new provider calls made during this handoff. Local Git/version/column/CSS state was inspected for this document. No new implementation, live test or provider call is authorized by this pause task.

## Technical snapshot and Git safety

| Item | State at pause |
|---|---|
| Project root | `C:\Users\Asus\DA Wordpress\Plugin-Ecomkit-VuiKhoe` |
| Branch | `laptop` |
| Application HEAD before this documentation commit | `9fee00f` — `feat: add TikTok Shop seller authorization` |
| Plugin | `0.7.35` |
| DB schema | `9` |
| Canonical | `v9`, exactly 24 visible columns |
| Migration in this task | NONE |
| Working tree before this task | Intentional uncommitted CSS only |
| Push in this task | NO |
| Production marketplace priority | Shopee → Lazada → TikTok Shop later |

This documentation receives a separate commit; `9fee00f` remains the application implementation reference, not the expected HEAD after committing this file. Determine the actual current HEAD when resuming.

Preserve the intentional dirty file **`ecomkit-vuikhoe/assets/css/admin.css`**. Its SHA256 at handoff is:

```text
2FB384F63E6EABBC3610AC6E37829EC182F4EA9E526A54B6824AF882FD500CAC
```

It predates this task. It must not be reset, restored, automatically stashed, modified, staged or committed by this documentation task. Before future work, inspect it:

```powershell
git status --short
git branch --show-current
git log --oneline --decorate -25
git diff -- ecomkit-vuikhoe/assets/css/admin.css
Get-FileHash -LiteralPath ecomkit-vuikhoe/assets/css/admin.css -Algorithm SHA256
```

Never use `git reset --hard`, `git clean -fd`, `git restore .`, `git add .`, `git add -A`, force push or automatic branch switching. Do not push. Stage only explicitly authorized paths. Do not change PHP, JavaScript, CSS, DB/schema, canonical definitions, API/OAuth, marketplace logic, Excel/PDF/clipboard, permissions, UI or version in this task.

## Canonical Result contract

Preserve this exact visible order:

1. Ngày Lên Đơn
2. Mã đơn ESHOP
3. Mã đơn sàn
4. Kênh Bán Hàng
5. Trạng Thái Đơn Hàng
6. Tên Khách Hàng
7. SĐT
8. Địa Chỉ
9. Tỉnh/TP
10. Ngày Xuất VAT
11. Ghi Chú
12. Đã Thu Tiền
13. Trạng Thái Công Nợ
14. Chênh lệch
15. Giá SP (VAT 8%)
16. % Tổng Chi Phí
17. Tổng Tiền Sẽ Thu
18. Phí Affiliate (Vui Khỏe)
19. Chiết Khấu (Vui Khỏe)
20. % Chiết Khấu Vui Khỏe
21. Phí Cố Định (TMĐT)
22. Phí dịch vụ (TMĐT)
23. Phí Giao Dịch (TMĐT)
24. % Chi Phí Sàn TMĐT

NULL displays `—`; explicit zero displays `0`. Marketplace reconciliation uses exact string equality between `orders.marketplace_order_id` and the provider order ID, scoped to platform/shop connection. Never fuzzy-match customer name, phone, address or product. Mã đơn ESHOP is an independent internal Excel identity. Preserve numeric-looking IDs as strings; never pass them through float or JavaScript Number.

## Shopee — WORKING / FROZEN BASELINE

Owner-reported production validation has proven OAuth/connection, Order data, exact reconciliation, Payment, financial mapping, 24-column Result, clipboard and temporary Shopee SPX PDF enrichment. Historical Batch evidence: **11 Orders / 11 Shopee / 11 of 11 reconciliation, Detail, Payment and Result**.

Golden order: `260924TSBR7FC0`.

| Verified canonical value | Value |
|---|---:|
| Product | 312000 |
| Affiliate | 0 |
| Discount | 17160 |
| Discount % | 5.50% |
| Fixed | 51480 |
| Service | 5700 |
| Transaction | 18720 |
| Platform % | 24.33% |
| Total % | 29.83% |
| Receivable | 218940 |

Established Shopee rules: `serviceFee >= 3000` means infrastructure `3000` and Voucher Xtra `serviceFee - 3000`; `serviceFee = 0` means both are `0`. PiShip uses the real provider value. Payment explicit affiliate zero remains zero; no Payment means NULL. Do not casually change these rules or reuse their meanings for Lazada/TikTok.

### Temporary PDF and clipboard

Shopee SPX text-layer PDF enrichment was live-tested successfully. It matches exact Mã đơn hàng and temporarily supplements customer Name + Address for clipboard. Phone remains unchanged/masked. No Media Library, DB, transient or browser storage persistence; uploaded PDF is deleted after processing.

Clipboard is plain TSV with 24 columns, current filters, raw numeric money, ratios as decimal fractions, NULL blank, explicit zero `0` and formula-injection protection. Both copy data and copy with headers are supported. **Clipboard is not WP.7 export.**

## Access and UI baseline

WP.6K.1 (`4c68e64`) uses centralized native capabilities: normal use is `manage_options OR edit_pages OR manage_woocommerce`; management is `manage_options` only. No dynamic custom-role provisioning.

| User | Access |
|---|---|
| Administrator | Full access: Tổng quan, Xử lý đơn hàng, Kết quả, Lỗi, Lịch sử, Marketplace, Cài đặt, advanced admin tools |
| Editor / Shop Manager | Normal operational workflow: Tổng quan, Xử lý đơn hàng, Kết quả, Lỗi, Lịch sử |
| Author / Contributor / Subscriber | No Ecomkit access under normal native capabilities |
| Legacy Ecomkit Operator | Historical role, not part of current policy; role name/custom legacy capabilities alone confer no access |

Editor/Shop Manager must not see or directly access Marketplace, Settings, OAuth/configuration, provider diagnostics or advanced admin tools. Server-side checks and nonces remain required. Shared company Batch/Result/History/Error visibility is preserved; no new per-user isolation.

WP.6K.2 UI modernization **was implemented** before TikTok, commit `cfd92de`, plugin `0.7.33`. Later TikTok commits are now present. The additional intentional CSS work remains uncommitted in `ecomkit-vuikhoe/assets/css/admin.css`; inspect its diff before any future development.

## Lazada — completed foundation, Orders and reconciliation

| Stage | Evidence / commit |
|---|---|
| WP.6J.1 foundation | PASS — `eb5a21e` |
| WP.6J.2 seller authorization | FULL PASS LIVE — `e9398d9` |
| WP.6J.3A Order client | PASS — `72f9e83` |
| WP.6J.3B diagnostics | FULL PASS LIVE after fixes |
| Browser route fix | `d73e662`: input `name="action"` shadowed `form.action`; literal attribute URL fixes the transport |
| Read transport fix | `4b969fe`: GET for GetOrders/GetOrder/GetOrderItems |
| WP.6J.4 exact reconciliation | FULL PASS LIVE — `8a92674` |
| Batch reconciliation control | `001eec3` |
| Persisted aggregate/checkpoint fix | `c78b3e0` |

Real shop: `100070635`. Exact verified order: `532935709720247`. GetOrders/GetOrder/GetOrderItems were live PASS. Known-ID reconciliation uses direct GetOrder, verifies exact provider ID, then GetOrderItems; GetOrders scanning is not a correctness dependency. Masked PII is accepted and is not a matching dependency. Generic batch-safe persistence and idempotence remain protected.

Live Batch #31: **50 orders = 43 Shopee + 7 Lazada**. All seven Lazada rows MATCHED, summary processed 7 / matched 7 / unmatched 0 / errors 0 / pending 0. Summary renders from persisted evidence without provider calls. Raw statuses are mutable evidence (`confirmed`, `shipped`, earlier `delivered`), not approved Vietnamese business mappings.

## Lazada finance — PARTIAL / PAUSED, critical blocker

WP.6J.5 source audit is PARTIAL. WP.6J.6 financial mapping is **NOT STARTED / BLOCKED**. Relevant implementation history: `de09d4f` audit, `7ec1350` diagnostic route fix, `d9a3e4e` discovery, `89a46f7` account scan, `ceec5c3` record-level normalization.

| API | Reported live result | Boundary |
|---|---|---|
| QueryTransactionDetails `/finance/transaction/details/get` | Provider PASS; tested individual orders returned 0 records | Missing transaction is not fee zero |
| GetPayoutStatus `/finance/payout/status/get` | Provider PASS; seller statements exist | SELLER_STATEMENT_NOT_ORDER; do not map a statement directly to an individual order |
| POST `/finance/transaction/accountTransactions/query` | HTTP 200, provider code 0, `data.transactions[]` | Account/shop discovery, no invented order linkage |

Latest owner-reported scan: **2026-09-01 → 2026-10-06**, provider records **39**, normalized **0**, normalization errors **39**. Each failure is field **`amount`**, observed type **string**, expected **exact decimal string / JSON number / null**, code **`ACCOUNT_AMOUNT_NOT_EXACT_DECIMAL`**. Provider transport succeeded; this is local amount normalization evidence, not provider failure. Do not loosen decimal validation blindly, use float, round, silently discard records or treat missing/masked amount as zero.

App Sensitive Data Privilege was **MASK**. Apply Unmask was submitted; Lazada mentioned approximately two weeks, which is an estimate, not an approval guarantee. **Strong hypothesis: amounts may be masked. This is NOT conclusively proven**, because diagnostics deliberately did not expose sensitive raw values. Approval alone does not authorize financial mapping.

Lazada canonical meaning remains UNKNOWN/unapproved for Affiliate, Discount Vui Khỏe, fixed/service/transaction fees, total receivable, paid, debt status, difference and platform cost ratios. Observed provider fields `price`, `item_price`, `paid_price`, `voucher`, `shipping_fee` do not establish approved canonical/VAT/seller-funded semantics. Never reuse Shopee fee rules. Payout joins and order/item aggregation require independently verified linkage.

### Exact Lazada resume procedure

Only after owner explicitly resumes the project:

1. Read this file and `lazada-integration.md`; verify Git state, version and intentional CSS diff/hash.
2. Confirm actual Unmask approval; deploy the current safe build and verify connection remains ACTIVE.
3. Explicitly rerun Account Transaction scan at `/finance/transaction/accountTransactions/query`, initially using the historical 2026-09-01 → 2026-10-06 window if still valid under the current official contract. Do not automatically crawl all pages.
4. Compare provider records, normalized records and normalization errors; specifically inspect safe amount type/normalization evidence. Desirable result: provider records > 0, normalized records > 0, exact-decimal amount parsing succeeds. Do not assume that result in advance.
5. Inspect safe field inventory, exact decimal strings/signs, transaction names/types, dates, statement references and order/item identifiers actually present. Preserve negative reversals and explicit zero; do not expose buyer PII or unknown sensitive text.
6. Establish exact transaction ↔ order/item linkage. If no verified order linkage exists, do not derive IDs from references or force canonical mapping.
7. Review/approve real finance evidence and the direct/derivable/unavailable/unknown source matrix; only then scope WP.6J.6. UNKNOWN values remain NULL.
8. After proven mapping, separately scope WP.6J.7 automatic normal-user pipeline. Diagnostic/manual reconciliation does not constitute the finished Lazada one-upload workflow.

## TikTok Shop — intentionally paused

Business/API registration with TikTok remains unfinished. Current company TikTok order volume is low, so this integration is not a business priority. Preserve completed work; do not continue or delete it.

| Stage | State | Commit / version |
|---|---|---|
| WP.6L.1 | Foundation PASS: TIKTOK platform, generic multi-shop connections, admin Marketplace card, App Key/Secret/Service ID, encrypted config; no migration/canonical change | `d95ff21`, 0.7.34 |
| WP.6L.1A | Official contract closure FULL PASS | `b9a2383` |
| WP.6L.2 | IMPLEMENTED / AUTOMATED PASS; live NOT DONE, therefore PARTIAL | `9fee00f`, 0.7.35 |

Contracts verified at WP.6L.1A are recorded in `tiktok-shop-integration.md`; recheck current official docs before resumed API development, preserving endpoint-specific versions:

| Capability | Contract baseline |
|---|---|
| ROW seller authorization | `https://services.tiktokshop.com/open/authorize`, `service_id` plus Ecomkit `state` |
| Callback | `code`, `state`; denial example `code=null&error=auth_denied`; code single-use, validity up to 30 minutes |
| Get Access Token | GET `https://auth.tiktok-shops.com/api/v2/token/get`, `grant_type=authorized_code`, seller `user_type=0` |
| Expiry | `data.access_token_expire_in`, `data.refresh_token_expire_in` are ABSOLUTE Unix timestamps, never durations |
| Refresh | GET `https://auth.tiktok-shops.com/api/v2/token/refresh`; accept complete returned pair, whether token rotates or stays identical |
| Authorized Shops | GET `/authorization/202309/shops`, scope `seller.authorization.info`, `data.shops[]`; no input shop_cipher required |
| Future Order List | POST `/order/202309/orders/search`, scope `seller.order.info` |
| Future Order Detail | GET `/order/202507/orders`, `ids`, maximum 50 order IDs |

Implemented WP.6L.2: seller authorization start, cryptographic state (internal 15-minute TTL, single use, admin/session/provider/config binding), callback validation, code exchange, seller/scope validation, encrypted tokens, Authorized Shops, exact VN shop binding, isolated TikTok signer, lifecycle/proactive refresh, atomic pair replacement, concurrency lock, reauthorization and safe Marketplace status/permission controls. Multiple shops require explicit admin choice. Failed authorization does not overwrite a valid connection. Absolute provider expiries are preserved; the ten-minute proactive window is Ecomkit policy, not provider lifetime. Uncertain refresh fails closed with the old encrypted pair retained.

Automated evidence at implementation: 48 PHP suites and 7 JS/browser suites PASS; real TikTok/Shopee/Lazada calls **0**. **No live seller authorization has been tested. WP.6L.2 is not FULL PASS.**

### Required TikTok resume/live gate

After owner reprioritizes TikTok, finish Partner Center/business/API onboarding first. Deploy 0.7.35 (or the then-reviewed safe build), configure the stable HTTPS callback and app fields, and as Administrator click **Kết nối TikTok Shop**. Verify seller user_type 0, Authorized Shops PASS, explicit intended Vietnam shop, exact shop ID/cipher stored securely, lifecycle READY and valid absolute access/refresh expiries. Run manual refresh once, reload Marketplace and confirm connection persists. Tokens/secret/signature/code must not be rendered or logged. Only after this live PASS may WP.6L.2 become FULL PASS and WP.6L.3 be scoped.

Not started: WP.6L.3 Order API; WP.6L.4 exact reconciliation; WP.6L.5 financial source audit; WP.6L.6 financial mapping; WP.6L.7 one-upload automatic pipeline. None may proceed before onboarding and live authorization pass.

TikTok Excel aliases are **not enabled**: no real business Excel sample has confirmed the company channel value. Do not blindly add TikTok/TikTok Shop/TIKTOK aliases. Future exact code belongs in generic `orders.marketplace_order_id`, not a new canonical column.

## Future backlog and resume priority

WP.7 is NOT STARTED: planned full XLSX/CSV export, 24 columns, one order per row, full Batch, status filtering, UTF-8 BOM CSV, formula-injection protection and safe filenames. Clipboard does not satisfy it. Do not start while the roadmap is paused.

Google Sheets is BACKLOG: configurable Spreadsheet ID/URL, monthly `T{MM}` tabs, fallback tab, audit-log tab, configurable order-code column, field-to-column mapping and encrypted service-account credentials. No implementation now.

Resume priorities: (1) Lazada Unmask outcome, (2) verified Lazada financial evidence, (3) WP.6J.6 only where evidence proves mapping, (4) Lazada automatic workflow, (5) TikTok when business onboarding becomes worthwhile, (6) WP.7 export, (7) Google Sheets. Owner decides the actual priority when resuming.

Do not casually alter frozen Shopee rules, canonical 24 columns, exact reconciliation, temporary PDF, clipboard, WP.6K.1 access, generic connections, encrypted credentials, DB9 or canonical v9 without a dedicated reviewed stage.

## Project status summary

| Area | Status | Next action |
|---|---|---|
| Shopee | FULL PASS / Production | Freeze baseline |
| Lazada OAuth | FULL PASS | None |
| Lazada Orders | FULL PASS | None |
| Lazada Reconciliation | FULL PASS | None |
| Lazada Finance | PARTIAL / PAUSED | Wait Unmask; rerun safe evidence audit |
| Lazada Financial Mapping | NOT STARTED / BLOCKED | Verified finance sources and approval first |
| TikTok Foundation | PASS | Paused |
| TikTok Auth | Automated PASS / Live pending | Business onboarding + live test |
| TikTok Orders | NOT STARTED | Paused |
| TikTok Finance | NOT STARTED | Paused |
| Access roles | PASS | Freeze |
| UI modernization | Implemented | Intentional CSS dirty work remains |
| WP.7 Export | NOT STARTED | Future |
| Google Sheets | BACKLOG | Future |

This handoff is documentation only: no application/version/schema/canonical change, no new tests needed, no credentials/private values included, and no provider calls. Commit only this file using `docs: record Ecomkit project pause state`; preserve unstaged CSS and do not push.

## STOP HERE

Ecomkit development was intentionally paused on 2026-10-07.

Do not continue coding automatically.

Before resuming, confirm:

1. Has Lazada Unmask been approved?
2. Is TikTok Shop now a business priority?
3. Has TikTok business/API onboarding been completed?
4. Is the current Git working tree understood?
5. Has the existing dirty CSS been reviewed?
6. Which marketplace is the actual current priority?

Only then create a new narrowly scoped stage.
