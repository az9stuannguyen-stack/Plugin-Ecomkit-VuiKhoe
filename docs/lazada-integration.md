# Lazada integration — WP.6J.5.2 Finance Transaction Discovery

Plugin 0.7.29, schema 9, canonical v9. No migration, dependency, extension or scheduled service added. Operator confirms OAuth, Order API and WP.6J.4 reconciliation FULL PASS LIVE: Batch #31 has seven Lazada matches, zero unmatched/errors/pending. Source-only Finance GET client and admin diagnostic are implemented; financial canonical mapping and automatic Lazada processing are NOT implemented. Automated tests use fake transport only. No real Finance request was made from this workspace. See [official Finance audit, full canonical matrix and live procedure](lazada-financial-source-audit.md) and [normalized official contract facts](lazada-finance-contract.json).

## Exact Batch reconciliation contract

Only `orders.platform = LAZADA` rows with a non-empty string `marketplace_order_id` are eligible. Primary lookup: Excel marketplace_order_id → GetOrder(exact string) → strict `===` providerOrderId and connectionId checks → GetOrderItems(exact string). GetOrders, order date, ESHOP code, product/SKU, name, phone and address are not correctness dependencies. Leading zeros and IDs beyond JavaScript's safe integer are preserved; provider IDs never become PHP int/float or JavaScript Number.

The explicit connection is an ACTIVE LAZADA entry in the existing generic connection model. READY/REFRESH_NEEDED uses the unchanged client/lifecycle, including refresh when required. REAUTH_REQUIRED blocks seller requests and records LAZADA_ORDER_AUTH_REQUIRED. Multiple active shops require explicit selection. Existing Order linkage cannot be rebound to a different shop; continuations are pinned to their selected connection. The same provider ID in different shops or historical Batches remains separate.

### Persistence and state

There is no external-provider order table to reuse: Shopee already persists provider evidence directly on the imported generic `orders` row. Lazada follows that model using existing `connection_id`, `matching_status`, `provider_normalized_data`, `matched_at` and `updated_at`. Existing unique key `(batch_id, connection_id, marketplace_order_id)` is unchanged; no schema change or new Lazada table. Original Excel identifiers/metadata/items, payment/income snapshots and canonical values remain untouched.

Safe Lazada order DTO (without addressShipping) and every provider item DTO are stored as a replaceable `order` + `items` array inside provider_normalized_data. Each item has its distinct string orderItemId; same SKU items remain separate. These are provider evidence, not new imported order_items rows. Reruns replace the array rather than append; no duplicate external orders/items. No raw buyer payload is stored; provider_raw_data is NULL. PII availability alone is preserved as MASKED/AVAILABLE/MISSING; missing or masked PII never blocks matching. Raw order/item statuses and original string monetary values are retained without Vietnamese status or financial mapping.

Current attempt outcomes:

| Case | Persisted matching_status | Summary |
| --- | --- | --- |
| Exact GetOrder and successful GetOrderItems | MATCHED | matched |
| Existing client demonstrates not found (GetOrder code 0 with empty data) | NOT_FOUND_IN_LAZADA | unmatched |
| Network, auth, HTTP, permission, malformed provider data or ID mismatch | ERROR | errors |
| Exact GetOrder followed by items failure | ERROR; exact_match=true retained | errors; GetOrder success recorded separately |

Other provider codes are conservatively ERROR, not inferred not-found. The unchanged client rejects unexpected provider ID as LAZADA_ORDER_INVALID_RESPONSE; the orchestrator also validates strict ID/connection equality. No fuzzy acceptance or blind retry. Summary matched/unmatched/errors are mutually exclusive; items errors do not masquerade as a complete match. Empty items are a successful API result with count 0, not an invented item.

Errors use generic `ecomkit_errors`, source LAZADA_RECON, preserving file/sheet/row, platform, exact marketplace ID, order/items stage, allowlisted code, friendly explanation, suggestion and existing redacted provider evidence. Each processed Order replaces only its own LAZADA_RECON errors; unrelated import/Shopee errors remain. Wrong-shop attempts retain the previous shop's provider snapshot. No token, signature, secret or raw buyer payload appears in stored diagnostics or admin output.

### Bounded processing and admin action

`Ecomkit_Vuikhoe_Lazada_Reconciliation_Service::reconcile_batch(batch_id, connection_id)` processes one eligible Order per explicit call by default. Internal callers may request at most five, with a 10-second elapsed budget checked between Orders. Each Order's evidence/state, errors and Batch checkpoint are committed in one transaction. A later failure cannot roll back earlier successful Orders. DB failures abort visibly; the uncommitted Order remains pending. The existing Lazada MySQL lock prevents concurrent reconciliation of one Batch; row identity is rechecked under FOR UPDATE before writes. Running automatic Shopee pipelines are blocked, not changed.

Batch source_metadata.lazada_reconciliation stores total/eligible/skipped, matched/unmatched/errors, per-API success counts, processed count, last imported row ID and selected connection. PROCESSING means more Orders remain: the next explicit call selects only nonterminal Orders; the cursor is diagnostic metadata rather than the work-selection authority. Completion is SUCCESS/WARNING/INCOMPLETE, and a subsequent run is a no-op for terminal Orders. Aggregate counters and pending work are derived from all persisted Lazada Order states; stale metadata/cursors cannot reset or skip them. No cron, automatic retries, unbounded parallelism or upload integration. API timeouts remain 20 seconds each: an individual GetOrder/GetOrderItems pair, plus optional existing token refresh, can exceed a short host/proxy request limit. This stage does not guarantee completion within every hosting limit; interruption before the transaction leaves that Order uncommitted for explicit resume.

In **Kết quả** or **Xử lý đơn hàng**, open the historical Batch and expand **Công cụ quản trị nâng cao** → **Đối chiếu Lazada cho Batch**. Select **Shop Lazada** and press **Đối chiếu Lazada**; if PROCESSING with pending Orders, use **Tiếp tục đối chiếu Lazada** until terminal. Admin-only POST route `admin_post_ecomkit_lazada_reconcile_batch`, manage_options and nonce action `ecomkit_lazada_reconcile_batch` / field `ecomkit_lazada_reconcile_nonce`. The block shows total/processed/matched/unmatched/errors/pending, plus up to 100 safe evidence rows with GetOrder/GetOrderItems PASS/FAIL, provider ID, item count, raw statuses and PII availability. This is separate from the unchanged canonical 24-column result table. The existing live diagnostic remains available.

WP.6J.4.2 fixes a UI wiring omission: 0.7.24 attached the control only to process.php, but the user's historical Batch page was results.php, whose advanced tools had no Lazada control. Version 0.7.26 reuses the same partial and existing reconciliation handler on both pages. Result counts identify Lazada Orders across the entire Batch, even when the canonical table is filtered. No Lazada section is rendered for a Batch without Lazada Orders. The selector includes ACTIVE connections only. The continuation retains the existing checkpoint's shop; no Order ID entry or shop reselection is required. An allowlisted return_page sends the POST back to the originating process/results page, with advanced tools expanded after the action. Matching, API client, persistence, OAuth, Shopee, schema and canonical logic are unchanged; the only service change is exposing already stored per-API booleans in the read-only evidence preview.

Manual retest for Batch #31 (operator-reported 50 Orders: 43 Shopee, 7 Lazada): deploy 0.7.26, open the Batch in Kết quả, expand advanced tools, select shop 100070635 and run until pending = 0. Confirm ID 532935709720247 is MATCHED with GetOrder/GetOrderItems PASS, exact provider ID, 1 item, delivered and masked PII accepted. Validate other IDs independently. Then separately click **Tạo / Làm mới kết quả 24 cột**. The Lazada POST handler does not materialize canonical results or invoke the automatic pipeline. Existing Result-page pipeline behavior is unchanged. Operator confirms live reconciliation PASS; Finance review is the new gate before WP.6J.6; WP.7 remains unopened.

### WP.6J.4 manual live gate

Deploy 0.7.26, hard refresh and use the existing historical Batch containing exact ID 532935709720247. Select shop 100070635; no reimport/date/list scan is required. Expected: MATCHED, GetOrder PASS, GetOrderItems PASS, exact provider ID, 1 item, raw statuses ["delivered"], MASKED accepted. Validate 529476639104228 independently if present; its existence is not assumed. Record summary/API/provider evidence, then rerun and verify no duplicate evidence/items or errors. Automated fixtures prove behavior only; Operator has now confirmed this live reconciliation gate FULL PASS. No financial PASS claim. WP.6J.5 source audit is implemented; WP.6J.6/.7 remain gated; WP.7 is not started.

## Architecture audit

| Existing component/files | Classification | Lazada use |
| --- | --- | --- |
| `class-ecomkit-db.php`: marketplace_connections, sync_runs, orders | GENERIC / REUSABLE | platform varchar(32); UNIQUE(platform,external_shop_id); LONGTEXT credentials/metadata; sync_runs references connection_id |
| credential-key-resolver / credential-encryption | GENERIC / REUSABLE | HKDF from WP salts or existing override; AES-256-GCM envelope v1; platform-specific AAD |
| marketplace-connection-service | Mixed | Existing Shopee methods unchanged. New list_platform/create_pending use exact platform+account identity; no credential returned by list_platform |
| shopee-config/http-client/oauth/token/order/payment/income/reconciliation | SHOPEE-SPECIFIC | Unchanged; no Lazada calls routed through Shopee |
| credential-mutation-lock | SHOPEE-SPECIFIC lock label | Not invoked by foundation; evaluate reuse during future OAuth updates without changing existing Shopee lock |
| admin Marketplace | REUSABLE presentation | Additional manage_options + nonce POST config form only |
| Excel/import/order models | GENERIC / REUSABLE | Combined “Sàn & Mã Đơn” cell: exact first line `Lazada`, second line trimmed code; platform LAZADA and orders.marketplace_order_id preserved as text |
| auto-pipeline | SHOPEE-SPECIFIC enrichment | LAZADA count recognized, but no Lazada provider stages added |
| Provider client abstraction | No generic HTTP/order interface currently | Minimal Provider_Configuration interface only; Order/Financial boundaries deferred until actual contracts exist |

No extraction/refactor is needed for foundation. Platform constants are additive; existing Shopee/Excel persisted values unchanged. Future TIKTOK support can extend validation without a new table.

## Hướng dẫn cấu hình & cài đặt

Use the existing WordPress/PHP requirements and Security Keys. As an existing authorized administrator (`manage_options`), open Ecomkit → Marketplace → Lazada Việt Nam. Enter the official application App Key and App Secret, then save. The full application credential payload is encrypted in option `ecomkit_vuikhoe_lazada_config` with AAD `ecomkit|lazada|provider-config|v1`; never expose that envelope in HTML, JS, notices or redirects.

Stored App Secret displays “Đã lưu an toàn”. Empty edit preserves the current envelope exactly; changing App Key requires explicit new secret. Replacement encrypts again with a fresh IV. Malformed/unreadable envelopes show safe not-configured state. No configuration exception text is sent to the browser. The handler uses a fixed saved/failed notice, never credential query parameters.

Country `vn` and official endpoints are centralized in Lazada_Config. Saving configuration does not insert a shop or declare it connected. Multiple accounts may coexist under the existing unique key `(platform, external_shop_id)`.

## Future credential / matching contracts

The app payload is v1 (`app_key`, `app_secret`, `country`). Authorized token pairs use the existing encryption service, AAD `ecomkit|lazada|vn|shop:<verified-id>|v1`, distinct from Shopee. Relative expiry seconds become absolute Unix timestamps. Safe metadata retains expiry/lifecycle/config fingerprint/actual identity path; full provider response and account email are not stored.

Future matching: Excel marketplace_order_id === official Lazada order ID, exact string identity after existing outer whitespace handling. No PII/fuzzy matching; no financial/status mapping inferred from Shopee.

## Live setup and authorization gate

1. Deploy plugin and remain logged in as the same WordPress administrator in the same browser/session.
2. Open Ecomkit → Marketplace → Lazada Việt Nam; configure real App Key/App Secret. Blank secret edit preserves the stored encrypted secret.
3. Copy the displayed HTTPS callback exactly into Lazada Open Platform application configuration. The stable URL is `<WordPress admin URL>/admin-post.php?action=ecomkit_lazada_oauth_callback`; do not append tokens/state or change query encoding. WordPress site/admin HTTPS configuration must be correct behind any proxy.
4. Click “Kết nối Lazada” (or “Ủy quyền lại Lazada”), authorize the Vietnam seller and return within 15 minutes. App binding/login/subscription eligibility is enforced by Lazada; the plugin does not bypass it.
5. Verify ACTIVE, READY, safe seller identity and `identity_path`. The live path is not yet known: parser accepts official `country_user_info_list` or `country_user_info`, selecting the unique VN entry and preferring returned `seller_id`, then `user_id`, then `short_code`. Never derives identity from email. Ambiguous VN identities or clearly non-VN response are rejected.

Authorization uses `https://auth.lazada.com/oauth/authorize`; code exchange and refresh use `https://auth.lazada.com/rest/auth/token/create` and `/auth/token/refresh`. Future Vietnam seller API base is `https://api.lazada.vn/rest`, not called here. Signed token requests use form POST, TLS verification, 20-second timeout and 256 KiB response limit. ASCII parameter ordering + API path prefix + HMAC-SHA256 produces uppercase hexadecimal signature.

State is 256-bit random, server-side transient TTL 900 seconds, bound to platform, user, session, config fingerprint and exact callback; locked single-use consumption precedes exchange. Missing/expired/replayed/tampered state never exchanges code. Authorization code is used in memory only and not persisted/logged by the plugin. Official code lifetime is approximately 30 minutes, but the plugin's shorter state TTL still applies. State echo and exact callback interoperability must be verified live; never disable checks to work around a provider/config issue.

## Token lifecycle and safe troubleshooting

`ensure_usable_access_token()` returns an already safe token without HTTP; refresh threshold is 1800 seconds. Refresh uses the latest encrypted refresh token under a per-shop database advisory lock. REFRESH_INFLIGHT is saved before HTTP; successful new access/refresh pair and lifecycle are written together. Refresh expiry cannot be extended beyond the previously known expiry. `refresh_expires_in=0` is non-refreshable; omitted expiry is not invented. A still-valid access token may be used until expiry if no refresh is available. Expired/invalid refresh requires reauthorization; uncertain refresh/persistence failure is fail-closed TOKEN_ERROR/inflight, never an automatic retry loop. Fresh authorization preserves existing credentials until a successful atomic replacement. Configuration changes require reauthorization.

Admin-only manual “Làm mới token Lazada” uses stored credentials; the card only reads safe state and does not refresh by rendering. Diagnostics show classification/stage, safe provider code/message, HTTP status, request ID and API path. Unknown provider errors remain generic exchange/refresh failures with actual safe evidence, not guessed causes. Compare the displayed callback with the exact provider configuration on live failures. No Orders/Payment/Income tools should be called during this gate.

The plugin does not log codes/tokens/secrets or put them into signed request URLs. OAuth necessarily delivers code in the inbound callback URL: configure web server/proxy/APM logs to redact callback query parameters and disable third-party request-body logging. Callback emits no-referrer and redirects immediately without code. WordPress hooks or infrastructure outside this plugin must also avoid logging sensitive HTTP bodies.

Lazada buyer PII availability can require **Sensitive Data Privilege / security audit**. Missing buyer name/address later is not by itself OAuth failure. No PII API or privilege bypass is implemented here.

Official references: [App parameters and privileges](https://open.lazada.com/apps/doc/doc?docId=108055&nodeId=10433), [Seller authorization](https://open.lazada.com/apps/doc/doc?docId=108260&nodeId=10533), [Signing contract](https://open.lazada.com/apps/doc/doc?docId=108068&nodeId=10400), [Official signing fixture](https://open.lazada.com/apps/doc/doc?docId=108069&nodeId=10400), [Token response](https://open.lazada.com/apps/doc/api?path=%2Fauth%2Ftoken%2Frefresh), [Refresh expiry policy](https://open.lazada.com/apps/doc/doc?docId=121782&nodeId=45695).

## Order client contract (automated-only)

`Ecomkit_Vuikhoe_Lazada_Order_Client` is not a pipeline stage. Its methods `get_orders(query)`, `get_order(id)`, `get_order_items(id)` use `/orders/get`, `/order/get`, `/order/items/get` at the centralized VN base. `/orders/items/get` is a route constant only: bulk request shape is deferred, not fabricated. WP.6J.3B adds an explicit admin diagnostic around these same methods, not a second client.

The read client obtains access through the existing lifecycle, signs the original common/business parameter values with the unchanged signer, adds sign, then encodes the same values with RFC3986 into the query string. GetOrders, GetOrder and GetOrderItems use HTTPS GET via wp_remote_get, with no POST body or form content type. TLS verification, no redirect, timeout 20 seconds, 2 MiB response limit and no automatic retries remain unchanged. Access token and sign now travel in the query; the credential-bearing full URL must never be logged. App Secret and refresh token are not sent in these requests. Diagnostics remain allowlisted to API path, HTTP method GET, connection ID, validated order ID, offset/limit, HTTP status, redacted provider code/message/request ID. Auth failures stop before seller HTTP. Unknown provider errors are not assigned speculative business meanings. OAuth/token endpoints retain their existing form POST transport.

WP.6J.3B.3 corrects the prior read-method mismatch only. Official references: [HTTP request sample for GetOrder](https://open.lazada.com/apps/doc/doc?docId=108069&nodeId=10400), [API Explorer HTTP methods](https://open.lazada.com/apps/doc/doc?docId=108328&nodeId=10833), and the Order tutorial/API references below. Fake transport tests verify all three GET paths, exact string IDs, query encoding/signature equality, unchanged pagination and safe errors. This patch is not proof that all production failures are resolved; live retest is required.

### App configuration notes (operator evidence)

Sensitive Data Privilege is currently **Mask**. Masked buyer fields are legitimate provider behavior, not an API failure; no masking bypass is attempted.

Lazada App Overview currently shows callback `https://vuikhoe.vn/`, while Ecomkit's callback is `https://vuikhoe.vn/wp-admin/admin-post.php?action=ecomkit_lazada_oauth_callback`. Correct this mismatch in the Lazada App configuration before future reauthorization. OAuth callback code is unchanged by this transport patch.

The immutable query accepts only officially verified minimum filters: timezone-aware `DateTimeImmutable created_after`, optional raw `status`, limit 1–100, offset 0–5000. Date serialization preserves the supplied timezone using ISO `Y-m-dTH:i:s±HH:MM`; no implicit PHP-local timezone. `created_before`, update filters and sort parameters are intentionally not sent until their current official contract is accessible/verified.

`paginate()` defaults to 100 per page, offsets 0/100/200, at most 51 pages. Full-page progression uses the requested limit. Short/empty page or coherent `countTotal` can prove completion. Repeated page/overlapping ID, changing/malformed count metadata, oversized page or contradictory short page fails closed. Safety page-bound exhaustion is an explicit pagination error; continuing beyond offset 5000 is `LAZADA_ORDER_WINDOW_TOO_LARGE`, requiring caller-controlled time-window splitting later. It never returns a partial listing as complete. Single-page `get_orders()` is explicitly one page, not an implicit full listing.

Provider IDs remain exact decimal strings paired with connection ID (Lazada order IDs are only shop-unique). DTOs preserve raw order status collections and individual item status strings, never one Vietnamese business label. Distinct item IDs sharing a SKU are preserved separately; duplicated item ID or wrong order association is rejected. Shipping components remain separate, with absent/masked PII accepted. They are in-memory potentially sensitive provider data, not diagnostics. No raw live payload, buyer PII or DTO is persisted by this client.

Price/voucher/shipping_fee and item prices are exact validated decimal strings, with explicit zero distinct from null. JSON numeric lexemes, including large IDs and decimals, are preserved before decoding; no PHP float accounting, scale conversion or financial calculation. Missing timestamps/fields remain null; returned timestamps remain provider strings, not guessed timezone dates. Unknown fields are not dumped into metadata. There is NO canonical product/receivable/fee/status mapping.

Official references: [Get Order tutorial: query, pagination, shop identity, item statuses and fields](https://open.lazada.com/apps/doc/doc?docId=121327&nodeId=29616), [GetOrders API](https://open.lazada.com/apps/doc/api?path=%2Forders%2Fget), [HTTP requests](https://open.lazada.com/apps/doc/doc?docId=108066&nodeId=10448), [Sensitive Data Privilege / DataMoat](https://open.lazada.com/apps/doc/doc?docId=108297&nodeId=10784). Masked/missing buyer data is not itself OAuth failure.

## Live gate — WP.6J.3B.2 procedure

1. Deploy 0.7.23, hard refresh admin and confirm Lazada shop 100070635 is ACTIVE/READY. Expand the Order diagnostic.
2. Copy the exact marketplace ID from the old Batch into the single Order ID field. No Batch reprocessing. Select date 2026-10-03 and paste ID 532935709720247 for the requested manual sample.
3. Press the single primary button. GetOrder then GetOrderItems run directly with the exact string; no list prerequisite and no date required for direct checks.
4. Enable the list check for this live transport retest. GetOrders runs after direct checks, using selected date midnight in Asia/Ho_Chi_Minh and the existing serializer. Only created_after is sent: one page, status all, limit 100, default offset 0. No hard end-of-day bound or automatic pagination. A missing ID means absent from this returned page, not absent from Lazada.
5. Review the friendly summary first: connection, direct order, exact comparison, items, raw API statuses and PII availability. List comparison is separate. Advanced settings contain manual offset; collapsed technical details contain redacted per-endpoint evidence, request IDs, HTTP/provider codes and structural paths. No raw buyer payload.
6. Expected manual result: READY, order found, GetOrder/GetOrderItems success, exact MATCH, no visible tokens/secrets. Record actual live results; this workspace has made zero real provider calls.

Response audit: the prior browser used response.json() and a single catch for parsing and network failures. Synthetic non-JSON/PHP and network responses reproduce that generic-error path; the original live response is unavailable, so its exact cause remains unconfirmed. The updated handler separates JSON provider errors, permission/nonce errors (including WordPress -1), malformed JSON/envelopes, PHP/non-JSON HTTP responses and network failures. Arbitrary response bodies are never displayed; safe backend messages and HTTP classifications are shown. No blind retries.

### HTTP 404 routing audit (0.7.22)

0.7.21 used `fetch(form.action, ...)` on a form containing `<input name="action">`. A real Chromium DOM confirms that this named input shadows the native action property: it is an HTMLInputElement, stringified as `[object HTMLInputElement]`. The browser resolves it relative to the admin page as `…/wp-admin/[object%20HTMLInputElement]`, instead of admin-ajax.php. `tests/lazada-diagnostic-dom-check.js` reproduces the old defect and verifies the actual fixed JS. The original production Network URL is unavailable; confirmation of this defect as the cause of that specific live request is pending.

The fix reads `form.getAttribute('action')`; WordPress still supplies the URL through `admin_url('admin-ajax.php')`, including subdirectory installs. Method: POST. Action before/after: `ecomkit_lazada_order_diagnostic`. Hook: `wp_ajax_ecomkit_lazada_order_diagnostic`, registered in plugin startup. Callback: `Ecomkit_Vuikhoe_Lazada_Order_Diagnostic::ajax`. Nonce field/action: `nonce` / `ecomkit_lazada_order_diagnostic`. Payload: action, nonce, WordPress _wp_http_referer, connection_id, order_date, order_id, offset and optional check_list. No route rename or client change.

The handler sends `X-Ecomkit-Lazada-Diagnostic: handler` and JSON transport evidence for handler entry, manage_options, nonce, client invocation and plugin version. Permission/nonce failures now return structured JSON HTTP 403. Provider failures remain inside the existing JSON endpoint results with safe API path/HTTP/provider-code/message/request-ID evidence. Fake Lazada HTTP 404 is verified to return WordPress HTTP 200 JSON with a nested API HTTP 404, not browser HTTP 404 non-JSON. Browser transport evidence shows endpoint/method/action/HTTP and handler marker, never raw response bodies. Missing handler evidence does not prove a provider call; headers may also be stripped.

Enqueue continues using ECOMKIT_VUIKHOE_VERSION, now `?ver=0.7.22`, without random versions. Deploy PHP and JS together and hard refresh; invalidate any cache that ignores query versions. Retest date 2026-10-03 and exact string ID 532935709720247: confirm admin-ajax.php and handler evidence first, then assess GetOrder/GetOrderItems. Automated real provider calls remain 0; live Order results pending. WP.6J.4 and WP.7 remain unopened.

The UI displays normalized provider amounts/statuses without interpreting accounting meaning. Shipping PII values are omitted from the diagnostic response; only component availability is shown: AVAILABLE/MASKED/MISSING (`*` indicates masking, presence is not a guarantee of full unmasked access). Buyer name/address missing or masked is not automatically an API failure and may require Sensitive Data Privilege. Item name/SKU is displayed only as returned and escaped as text.

Response lives only in the AJAX response/browser memory; no options/transients, business tables, raw snapshots or canonical rows are written by the diagnostic. Reload clears it. The existing token lifecycle may rotate encrypted credentials as required, which is distinct from order payload persistence. No provider errors are retried blindly, and tokens/secrets/signatures are never displayed/logged by this tool. External web/APM HTTP-body logging must remain disabled/redacted as described above.

The earlier Order API live gate is now reported FULL PASS by the operator (evidence at the top). The historical diagnostic/routing/transport procedures above describe previous gates and fixes, not a new claim of calls from this workspace. Current next gate is WP.6J.4 live Batch reconciliation; WP.7 remains unopened.

## WP.6J.4.2 aggregate correction

The prior control displayed source_metadata.lazada_reconciliation counters. A completed explicit rerun reset those counters and processed one Order, while retaining all other terminal evidence. Thus seven MATCHED rows could coexist with processed=1 and pending=6. The summary now reads the entire Batch of persisted Lazada Orders, independently of the 100-row evidence preview. Blank IDs are skipped, not pending. MATCHED/NOT_FOUND_IN_LAZADA (UNMATCHED)/ERROR are terminal; errors remain errors. Refresh/navigation performs DB reads only, no seller request.

Explicit reconciliation rebuilds counters from persisted rows, processes only actual pending Orders (including holes before a stale cursor), and repairs completed checkpoint metadata without provider calls or evidence/item/error writes. No automatic reset or retry of terminal Orders is introduced. An intentional reset/revalidation control is outside this patch. Existing provider matching, shop checks, raw statuses, canonical and financial mapping are unchanged.

Main progress reconciliation/detail counters are Shopee-only, as shown by their Shopee denominators and the Auto Pipeline implementation. Their labels now say Shopee in both initial PHP and polling JavaScript. Lazada has its own persistent aggregate line; Payment/Income are not merged.

Manual gate: deploy 0.7.26 and open Batch #31 without pressing reconciliation. The existing seven terminal matches must show total/processed/matched=7, unmatched/errors/pending=0, no Continue button, and identical evidence/raw statuses. This is an automated fixture PASS only until that live state is confirmed; do not start WP.6J.5 or WP.7.


## WP.6J.5.3 ? Account transaction scan (0.7.30)

Official contract audited 2026-10-06: https://open.lazada.com/apps/doc/api?path=/finance/transaction/accountTransactions/query
Machine-readable official reference: `docs/lazada-account-transactions-contract.json`.
Account discovery uses POST `/finance/transaction/accountTransactions/query`, required `start_time`/`end_time` in yyyyMMdd, `page_num` and `page_size`. Optional documented filters (not sent) are transaction_type, sub_transaction_type, transaction_number. No trade_order_id, offset, limit or trans_type is sent. A conservative local diagnostic window remains under 180 days; this is not claimed as an account API constraint. One explicit page per click, next page only from provider page_info.total_page.
Official envelope: success/error_code/msg, data.page_info and data.transactions[]. Safe fields retained: type, sub_type, amount, currency, pmt_reference, transaction_number, transaction_time. Payee accounts, remarks and tracking free text excluded. These are documented paths, not observed live account response evidence.
No documented order/item linking field exists here. Payment reference is NOT treated as an order ID. Exact LAZADA/selected connection order lookup remains for order-mode order_no. Order mode uses QueryTransactionDetails with exact trade_order_id by default; legacy singular diagnostic remains available. Payout remains SELLER_STATEMENT_NOT_ORDER. No canonical/finance writes, no fee mapping, no migration or Shopee semantic reuse.
Reproduced previous metadata-loss mechanism: decimal/identifier normalization threw plain RuntimeException after HTTP, so diagnostic catch produced empty evidence. It now wraps normalization failure in Provider_Exception preserving safe HTTP metadata. Actual live offending field/envelope cannot be determined without a safe response structural observation; handler/client-invoked flags alone do not prove HTTP occurred.
Manual gate: deploy 0.7.30, hard refresh, select scan, shop 100070635, dates 2026-09-01 through 2026-10-06, page 1. Inspect safe rows and page_info; explicitly advance pages. Empty transactions[] is valid evidence, not zero fees. Keep WP.6J.6/WP.7 closed pending review.


## WP.6J.5.4 ? Safe account normalization (0.7.31)

Live report: account POST HTTP 200/code 0, request_id present, data.transactions[] recognized; local normalization failed. Actual failing transaction index/field/type was not supplied, so its precise cause is unresolved pending retest. No live payload was fetched or saved.
Audit found the previous account loop discarded the entire page on invalid amount, non-object record or strict page_info failure. Original numeric JSON lexemes were already preserved, so ordinary JSON integer/decimal alone was not established as the live cause.
The decoder now retains original JSON types for structural evidence only; money/reference values still come from lossless numeric lexemes. Numeric decimal/exponent amounts are normalized using string manipulation, with existing precision bounds and no float arithmetic. No identifier goes through int/float/JavaScript Number. All documented account transaction fields are optional. Null/absent/empty money remains unknown; invalid nonempty money records are reported explicitly. Unsupported optional scalar fields produce safe warnings, unknown nested values are excluded.
A successful provider page returns provider_success, provider count, normalized count, separate LAZADA_FINANCE_NORMALIZATION_ERROR records, zero-based transaction index, field, original JSON type, expected type and safe code. Field inventory lists sanitized keys/types/classification only; unknown field values, payee accounts, remarks and tracking free text are never rendered. Pagination anomalies disable unsafe continuation and report warnings rather than erasing transactions.
Account columns are limited to documented fields actually present. No order linkage is inferred from pmt_reference. Payout remains unchanged SELLER_STATEMENT_NOT_ORDER. QueryTransactionDetails, transport, signer, token lifecycle, DB 9 and canonical v9 remain unchanged. No canonical/finance writes or fee mapping.
Retest shop 100070635, scan 2026-09-01 through 2026-10-06, page 1. Capture safe table/inventory/errors, especially transaction index + field + observed type + code. Provider PASS and local normalization errors are separate. Do not start WP.6J.6 or WP.7.
