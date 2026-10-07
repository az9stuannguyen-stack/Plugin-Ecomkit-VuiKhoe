# TikTok Shop Vietnam — WP.6L.1 foundation

Audit date: 2026-10-07. Plugin 0.7.33 → 0.7.34, starting HEAD cfd92de. TikTok Shop seller integration only; not Login Kit, social, Research or creator integration. No production provider calls, OAuth, token operations, Order client, finance, reconciliation or canonical writes are implemented.

## WP.6L.1A — official contract closure (2026-10-07)

This closure supersedes the initial WP.6L.1 gaps below. Starting HEAD d95ff21; plugin remains 0.7.34. Documentation only. Official document content and API metadata were retrieved anonymously from Partner Center's own document delivery service, discovered in its public page bundle. No seller authorization, token or business endpoint was contacted. Browser inventory was empty; direct web page reads returned JavaScript shells.

Reproduction: `https://partner.tiktokshop.com/api/v1/document/tree?workspace_id=3`; then `/api/v1/document/detail?workspace_id=3&document_id=ID` and `/api/v1/document/api_meta?src_document_id=ID`. Endpoint version lists came from `/api/v1/document/api_external/list?document_id=ID&api_config_version=2`. These are public documentation reads, not production business API calls. Only contract summaries are committed; no raw metadata, operator contact fields, sample tokens or buyer examples are retained.

| Capability | Method | Path | Version | Scope | Verified |
|---|---|---|---|---|---|
| Seller Authorization (ROW/Vietnam) | Browser navigation / redirect | `https://services.tiktokshop.com/open/authorize` | Unversioned entry | App-enabled scopes approved by seller | VERIFIED [Authorization overview](https://partner.tiktokshop.com/docv2/page/authorization-overview-202407), doc 678e3a3292b0f40314a92d75 |
| Get Access Token | GET | `https://auth.tiktok-shops.com/api/v2/token/get` | v2 | Returns granted scopes | VERIFIED [Seller token tutorial](https://partner.tiktokshop.com/docv2/page/generate-test-access-token), doc 686da77b1aa957049adb3410 |
| Refresh Access Token | GET | `https://auth.tiktok-shops.com/api/v2/token/refresh` | v2 | Returned granted scopes must be revalidated | VERIFIED [Authorization overview](https://partner.tiktokshop.com/docv2/page/authorization-overview-202407) |
| Get Authorized Shops | GET | `/authorization/202309/shops` | 202309 | `seller.authorization.info` or documented FS alternative* | VERIFIED [Authorized Shops](https://partner.tiktokshop.com/docv2/page/get-authorized-shops-202309), doc 6507ead7b99d5302be949ba9 |
| Get Order List | POST | `/order/202309/orders/search` | 202309 | `seller.order.info` or FS alternative* | VERIFIED [Order List](https://partner.tiktokshop.com/docv2/page/get-order-list-202309), doc 650aa8094a0bb702c06df242 |
| Get Order Detail | GET | `/order/202507/orders` | 202507 | `seller.order.info` or FS alternative* | VERIFIED [Order Detail](https://partner.tiktokshop.com/docv2/page/get-order-detail-202507), doc 6894134ba28e5204961601a5 |

*The metadata also lists `seller.fs.order&fufillment.management` (provider spelling preserved). Ecomkit's planned minimum scopes are the seller authorization/order scopes, not fulfillment management. Each endpoint has its own version. The official Order Detail version list contains 202507 and 202309; 202507 is the current reference selected in the document tree. Shops and Order List list only 202309. Do not impose a global API version.

### Seller authorization and callback

ROW includes Vietnam. Seller links require `service_id`, not creator `app_key`; Ecomkit adds `state`. Redirect URL comes from app configuration, not an invented authorization query parameter. Browser consent leads back to that URL with `code` and supplied `state`. Rejection returns `code=null&error=auth_denied`. The code is one-use, valid 30 minutes, and passed to token exchange as `auth_code`. State is recommended by TikTok and mandatory in Ecomkit's future implementation. Source: [Authorization overview](https://partner.tiktokshop.com/docv2/page/authorization-overview-202407).

[Seller guide](https://partner.tiktokshop.com/docv2/page/seller-authorization-guide), doc 678e3a344ddec3030b238fa0, confirms link and public App Store flows, configured callback, renewal and seller deauthorization webhook. Deauthorization is distinct from rejecting initial consent. No complete enumeration of other callback error values is published in the retrieved documents: UNKNOWN; reject any error or absent/null code without exchange. Creator entry `shop.tiktok.com/alliance/creator/auth` and creator `user_type=1` do not belong in seller flow.

### Token response, expiry and refresh strategy

Get requires query `app_key`, `app_secret`, `auth_code`, `grant_type=authorized_code`. Success envelope is `code=0`, `message`, `request_id`, `data`. Data includes string `access_token`, `refresh_token`, `open_id`, `seller_name`, `seller_base_region`; integer `user_type`; array-of-string `granted_scopes`. Standard seller is `user_type=0`; global-selling types 4/5 are separate and outside initial local Vietnam flow. See [seller token tutorial](https://partner.tiktokshop.com/docv2/page/generate-test-access-token).

`data.access_token_expire_in` and `data.refresh_token_expire_in` are absolute Unix expiration timestamps (seconds), despite their names. The tutorial documents a seven-day access default; implementation must use returned timestamps, never now+7 days. Refresh expiration follows granted authorization duration, not an invented fixed lifetime. Future code must validate future epoch values and required fields, persist UTC expirations, refresh before access expiry, and require reauthorization at refresh expiry. Missing/invalid expiry fails closed, never READY.

Refresh requires query `app_key`, `app_secret`, `refresh_token`, `grant_type=refresh_token`. [Authorization overview](https://partner.tiktokshop.com/docv2/page/authorization-overview-202407) states the same response shape, including both tokens and both expiry timestamps. A returned refresh token is verified; guaranteed rotation, whether the previous token remains usable, and overlap duration are UNKNOWN. Safe strategy: validate and atomically replace the entire returned token pair/expiry set; never assume old-token reuse. Nonzero code is failure; retain safe message/request_id only. Full numeric refresh-error enumeration is not supplied here; expired/revoked authorization requires reauthorization per the seller tutorial.

### Authorized Shops identity

Required query: `app_key`, `sign`, `timestamp`; headers: `x-tts-access-token` (seller token) and `content-type: application/json`. **No shop_cipher**, body, cursor or pagination parameter is declared. Response is `data.shops[]`: string `id`, `cipher`, `name`, `region`, `seller_type`, `code`. `seller_type` is LOCAL/CROSS_BORDER. The input named `shop_cipher` in subsequent APIs is returned here as **cipher**, not `shop_cipher`; shop ID is **id**, not `shop_id`. Source: [Authorized Shops](https://partner.tiktokshop.com/docv2/page/get-authorized-shops-202309), including its API metadata.

Persist exact `id` as generic external shop identity and exact `cipher` for API requests, with region/type validation; name/code are display metadata. Require intended Vietnam region and verified scope before READY. Preserve all identifiers as strings; do not decrypt cipher or derive identity from names/email. Multiple returned shops require explicit shop selection. No live shop response was obtained.

### Signature and common request contract

[Official signing guide](https://partner.tiktokshop.com/docv2/page/sign-your-api-request), doc 678e3a3d4ddec3030b238faf, verifies: exclude query `sign`/`access_token`; sort remaining names ascending alphabetically; concatenate each name+value without separators; prefix the exact API path (category/version/resource, no host/query); append sent body bytes unless multipart/form-data; wrap with App Secret on both ends; HMAC-SHA256 using App Secret; lowercase hexadecimal output. Headers/access token are not included in this signature input. JSON serialization must be identical to transmitted bytes. No pure fixture is added: examples provide algorithms but no complete fixed input plus independently stated expected digest. No signer/runtime code changes.

[Common parameters](https://partner.tiktokshop.com/docv2/page/common-parameters), doc 678e3a4278f4c20311b8b57e: business query `app_key`, `sign`, `timestamp` unless endpoint says otherwise; timestamp is ten-digit epoch seconds, accepted from current time minus five minutes through plus thirty seconds. For 202309+ send token in `x-tts-access-token`; JSON bodies use `application/json`. `shop_cipher` is endpoint-specific, required for these Order endpoints and omitted for Authorized Shops. Token GET/refresh contracts are separate; do not add business signing parameters to them speculatively.

### Orders — verified structure, no mapping

[Order List](https://partner.tiktokshop.com/docv2/page/get-order-list-202309): required query `app_key/sign/timestamp/shop_cipher/page_size` (1–100); optional `page_token`, `sort_order` ASC/DESC, `sort_field` create_time/update_time. Headers seller token and JSON. Optional JSON filters `order_status`, `create_time_ge/create_time_lt`, `update_time_ge/update_time_lt` use Unix seconds, inclusive lower/exclusive upper; a missing opposite bound defaults to current time/earliest shop time as documented. Update timestamps can exceed the search window during ongoing refresh. Response `data.orders[].id` is string, `data.next_page_token` opaque string, `data.total_count` integer. Keep IDs exact; pagination remains bounded in future implementation.

[Order Detail](https://partner.tiktokshop.com/docv2/page/get-order-detail-202507): required query `app_key/sign/timestamp/shop_cipher/ids`; `ids` is string list, maximum 50, serialized comma-separated in the official sample. No path parameter, JSON request body or pagination. Headers seller token and JSON. Envelope `code/message/request_id/data`; records `data.orders[]`, exact string `id`, raw string `status`.

Documented paths under each order: `payment` (string currency/sub_total/total_amount/original_total_product_price/seller_discount/platform_discount/shipping_fee), `recipient_address` (name/phone_number/full_address/address lines/district_info), `user_id`, `packages[].id`, `shipping_provider_id`, `tracking_number`, `shipping_type`, `line_items[]` (id/sku_id/product_id/product_name/sku_name/seller_sku/sale_price/original_price/package_id/tracking_number). Availability/masking and country-specific fields apply; never treat unavailable PII as match failure. Buyer payment is not seller settlement. No financial/status canonical mapping is approved here.

### Security and next-stage gate

WP.6L.2 requires HTTPS callback and validation before exchange; random state with TTL, single-use and admin/session/provider/config binding; encrypted server-only App Secret/tokens; server-only signing; redacted URLs/errors; atomic token replacement. Invalid state, denial, unexpected identity/scope/region or malformed expiry must stop before business calls. No real auth code/token may be placed in logs, screenshots, browser token URLs or repository.

All WP.6L.2 prerequisites in the requested gate are VERIFIED: seller URL/parameters/callback, token/refresh endpoints, seller identity, Authorized Shops/cipher, signing and expiry handling strategy. Unknown rotation guarantees/full error enumeration are addressed with conservative fail-closed handling, not assumptions. **WP.6L.2 prerequisites were closed here; see the implementation section below for the subsequently authorized stage.** Order Detail contract is now verified for WP.6L.3. Real TikTok/Shopee/Lazada business calls remain 0; DB9/canonical v9/plugin0.7.34 unchanged; existing dirty CSS preserved.

## Implemented architecture

`TIKTOK` joins the generic string platform validator. Existing DB9 marketplace connection identity `(platform, external_shop_id)` supports multiple shops. Existing `create_pending()` can reserve a real known external identity with `PENDING_AUTH`, null token credentials; saving application configuration creates no invented shop row and never marks ACTIVE.

TikTok configuration uses its own option and AES-256-GCM credential envelope, with provider-specific authenticated context. App Key, App Secret and Service ID are stored together in the encrypted envelope. Blank secret edit preserves it byte-for-byte; identity changes require intentional secret replacement. Safe UI projection excludes secret/token data. Shopee/Lazada options are untouched.

Marketplace card and save action require existing `manage_options`; configuration changes also require `ecomkit_tiktok_nonce`. Editor, Shop Manager and lower roles cannot render the card or use the endpoint. The stable HTTPS callback is `admin-post.php?action=ecomkit_tiktok_oauth_callback`. WP.6L.2 replaces the foundation denial handler with the session-bound seller callback. Anonymous callback requests are routed only to the same server-side Administrator denial.

`Ecomkit_Vuikhoe_Tiktok_Http_Client` exposes only token exchange, refresh and Authorized Shops at the fixed official hosts/paths. No arbitrary URL or Order API method exists. GET requests use TLS verification, timeout 20 seconds, no redirects, a 1 MiB bounded JSON response and depth 32. Provider code must be integer 0. Diagnostics contain only path/method/status/code and an allowlisted request_id checked against sensitive parameters; provider message/body is never projected. Token endpoint query secrets remain server-side and must never enter browser/UI/logs.

## Excel and canonical audit

Existing combined identity parser recognizes exactly `Shopee` and `Lazada`. `TikTok`, `TikTok Shop`, `TIKTOK` currently return `EXCEL_UNKNOWN_PLATFORM`. No business Excel fixture establishes an approved TikTok label, so no speculative alias is introduced. A later verified parser extension must preserve exact string IDs in the existing `orders.marketplace_order_id`, without numeric conversion or a separate TikTok column. Schema and generic order ID storage already support strings; no migration is needed. Canonical v9 and all 24 columns remain unchanged.

## WP.6L.2 authorization security requirements

Use cryptographically random, single-use, expiring state bound to administrator, session, provider and configuration fingerprint. Validate callback/state/code server-side before token exchange. Never log authorization code. Validate seller identity and required granted scopes; encrypt tokens, replace returned token pairs atomically and handle reauthorization without corrupting an existing working connection. Call Get Authorized Shops after authorization and verify exact shop ID/cipher/region before READY. IDs and ciphers remain strings; names are display only. Use the verified absolute Unix expiry fields described in WP.6L.1A; do not derive expiration from hardcoded lifetimes.

## Stages and verification

WP.6L.1 foundation → WP.6L.2 seller authorization/token lifecycle/authorized shops → WP.6L.3 Order client/live validation → WP.6L.4 exact reconciliation → WP.6L.5 financial source audit → WP.6L.6 proven financial mapping → WP.6L.7 one-upload pipeline.

Fake fixtures verify encryption, isolation, blank/replacement edits, pending multi-shop state, actual card rendering, permission/nonce boundaries, HTTPS callback and current parser behavior. Existing regression suites cover roles, Shopee, Lazada, PDF, clipboard and 24 columns. All automated provider calls remain zero. WP.6L.1 checks covered configuration only; WP.6L.2 manual authorization gate follows below.

Lazada WP.6J.6 remains paused pending Unmask approval. WP.7 is not started.

## WP.6L.2 implemented seller authorization — 0.7.35

Administrator-only `admin-post` actions: `ecomkit_tiktok_oauth_start`, `ecomkit_tiktok_oauth_callback`, `ecomkit_tiktok_oauth_select`, `ecomkit_tiktok_oauth_refresh`. POST mutations require the matching action nonce in `ecomkit_tiktok_nonce`. The provider callback validates state instead of a WordPress nonce; it requires the original authenticated Administrator/session. Existing Editor/Shop Manager operational permissions are unchanged.

Authorization uses only `service_id` and random 32-byte hex `state` at the official ROW seller URL. State metadata is bound to TIKTOK, Administrator, hashed session, configuration fingerprint and stable HTTPS callback. Internal TTL is 15 minutes; a TikTok-specific MySQL named lock protects validation and single-use consumption before exchange. Missing/malformed/expired/replayed/wrong-session state and denied/missing codes never contact the provider. Codes are not persisted, logged or included in redirects. Callback redirects remove the code from the destination and send Referrer-Policy no-referrer. Administrators must also ensure infrastructure/access logs redact callback codes and token endpoint queries.

Token exchange accepts only seller `user_type=0`, `seller.authorization.info`, complete nonempty access/refresh/open_id and future absolute expiry timestamps. No fixed provider lifetime is assumed. Authorized Shops uses signed GET with the token header and no shop_cipher parameter. Zero shops fail; one verified VN shop binds automatically; multiple shops require explicit Administrator selection. Pending selection retains the token pair/shop list only in an encrypted transient for 15 minutes, bound to Administrator/session/configuration; selection is single-use under a named lock. Shop names are display only. IDs/ciphers remain exact strings. Store all documented shop metadata and the cipher inside the generic connection's authenticated encrypted envelope; external shop ID uses existing `(platform, external_shop_id)` identity. No TikTok table or migration.

Activation requires both validated tokens and a bound VN shop. Reauthorization failure/cancellation leaves existing credentials untouched. Atomic replacement is one database update of the complete encrypted pair and metadata, under the same shop lock used for refresh. Provider timestamps are stored as absolute Unix seconds, not `now + expire_in`. READY/REFRESH_SOON/EXPIRED/REAUTH_REQUIRED/INVALID are calculated from stored data without HTTP calls on page rendering. Ten-minute proactive refresh is Ecomkit policy, not provider lifetime.

Refresh accepts both unchanged and rotated returned refresh tokens. It validates the complete seller response, scope, expiry, open_id consistency and configuration fingerprint before replacing credentials. A competing refresh rereads the row after acquiring the shop lock and reuses the winner's ready pair. Expired refresh or changed configuration requires reauthorization without sending HTTP. An inflight metadata marker is persisted before contacting TikTok; crash/network/partial-response uncertainty leaves the previous encrypted pair intact and fails closed as INVALID, requiring reauthorization. No blind retry or speculative old-refresh-token reuse. The lock waits at most two seconds.

Marketplace shows configuration, waiting, bound shop/region, granted scopes, lifecycle, UTC expiries, connect/reauthorize and manual refresh controls. Multiple-shop selection excludes cipher/tokens. Safe notices use internal enumerated errors and safe path/status/code/request_id only. No raw response, buyer PII, secret, token, signature or signed URL is rendered/logged. PHP fake fixtures cover controller permission/nonce handling, callback state, isolated signing, encrypted binding, multiple shops, absolute expiry, same/rotated refresh, overlapping refreshes, failed refresh, safe HTML and zero display calls. Synthetic signer vectors test the verified algorithm; they are not presented as official digest fixtures.

Automated provider calls: TikTok 0, Shopee 0, Lazada 0. Existing role/business/PDF/clipboard/24-column regressions remain required. DB9, canonical v9 and 24 columns unchanged. No TikTok Excel alias, Order API, reconciliation, finance or canonical writes are implemented.

### Manual live gate — not yet executed

Deploy 0.7.35. As Administrator configure App Key/Secret/Service ID and the unchanged HTTPS callback in Partner Center. Click Kết nối TikTok Shop, authorize the company's seller account, and explicitly select the intended VN shop if multiple are returned. Confirm Đã kết nối / READY, exact shop ID, region, scope and provider-derived expiries. Verify no secret/token appears; run manual refresh once and reload Marketplace to confirm persistence. Cancellation or invalid state must produce safe feedback without replacing the working connection. Test Editor/Shop Manager direct mutation denial. WP.6L.2 remains PARTIAL pending this live gate; do not start WP.6L.3 before live PASS. Lazada WP.6J.6 and WP.7 remain out of scope.
