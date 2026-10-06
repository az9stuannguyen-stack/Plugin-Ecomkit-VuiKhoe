# Lazada authorization, Order client and live diagnostic — WP.6J.3B

Plugin 0.7.22, schema 9, canonical v9. No migration, dependency, extension, environment variable or scheduled service added. OAuth/token refresh, an in-memory Order client and an admin live diagnostic are implemented; Financial API, reconciliation and automatic Lazada order processing are NOT implemented. Automated tests use fake transport only. Operator has confirmed live OAuth ACTIVE/READY; live Order response validation remains PENDING. No real Order request was made from this development workspace.

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

The client obtains access through the existing lifecycle, then signs form POST common/business parameters with the existing Lazada signer. HTTP uses TLS verification, no redirect, timeout 20 seconds, 2 MiB response limit and no automatic retries. Tokens/signature/secret never enter request URL or client logs. Diagnostics are allowlisted to API path, connection ID, validated order ID, offset/limit, HTTP status, redacted provider code/message/request ID. Auth failures stop before seller HTTP. Unknown provider errors are not assigned speculative business meanings.

The immutable query accepts only officially verified minimum filters: timezone-aware `DateTimeImmutable created_after`, optional raw `status`, limit 1–100, offset 0–5000. Date serialization preserves the supplied timezone using ISO `Y-m-dTH:i:s±HH:MM`; no implicit PHP-local timezone. `created_before`, update filters and sort parameters are intentionally not sent until their current official contract is accessible/verified.

`paginate()` defaults to 100 per page, offsets 0/100/200, at most 51 pages. Full-page progression uses the requested limit. Short/empty page or coherent `countTotal` can prove completion. Repeated page/overlapping ID, changing/malformed count metadata, oversized page or contradictory short page fails closed. Safety page-bound exhaustion is an explicit pagination error; continuing beyond offset 5000 is `LAZADA_ORDER_WINDOW_TOO_LARGE`, requiring caller-controlled time-window splitting later. It never returns a partial listing as complete. Single-page `get_orders()` is explicitly one page, not an implicit full listing.

Provider IDs remain exact decimal strings paired with connection ID (Lazada order IDs are only shop-unique). DTOs preserve raw order status collections and individual item status strings, never one Vietnamese business label. Distinct item IDs sharing a SKU are preserved separately; duplicated item ID or wrong order association is rejected. Shipping components remain separate, with absent/masked PII accepted. They are in-memory potentially sensitive provider data, not diagnostics. No raw live payload, buyer PII or DTO is persisted by this client.

Price/voucher/shipping_fee and item prices are exact validated decimal strings, with explicit zero distinct from null. JSON numeric lexemes, including large IDs and decimals, are preserved before decoding; no PHP float accounting, scale conversion or financial calculation. Missing timestamps/fields remain null; returned timestamps remain provider strings, not guessed timezone dates. Unknown fields are not dumped into metadata. There is NO canonical product/receivable/fee/status mapping.

Official references: [Get Order tutorial: query, pagination, shop identity, item statuses and fields](https://open.lazada.com/apps/doc/doc?docId=121327&nodeId=29616), [GetOrders API](https://open.lazada.com/apps/doc/api?path=%2Forders%2Fget), [HTTP requests](https://open.lazada.com/apps/doc/doc?docId=108066&nodeId=10448), [Sensitive Data Privilege / DataMoat](https://open.lazada.com/apps/doc/doc?docId=108297&nodeId=10784). Masked/missing buyer data is not itself OAuth failure.

## Live gate — WP.6J.3B.2 procedure

1. Deploy 0.7.22; confirm the Lazada shop is ACTIVE/READY. Expand the Order diagnostic.
2. Copy the exact marketplace ID from the old Batch into the single Order ID field. No Batch reprocessing. Select date 2026-10-03 and paste ID 532935709720247 for the requested manual sample.
3. Press the single primary button. GetOrder then GetOrderItems run directly with the exact string; no list prerequisite and no date required for direct checks.
4. Optionally enable the list check. GetOrders runs after direct checks, using selected date midnight in Asia/Ho_Chi_Minh and the existing serializer. Only created_after is sent: one page, limit 100, default offset 0. No hard end-of-day bound or automatic pagination. A missing ID means absent from this returned page, not absent from Lazada.
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

Live report must record all three API outcomes, exact identity/status evidence, PII availability, offsets/request count, actual structural paths and one Excel exact comparison. No live Order call/result is available in this workspace yet; do not invent order IDs/statuses. Only after GetOrders/GetOrder/GetOrderItems and secret-safety PASS live may WP.6J.4 begin. WP.7 remains blocked.
