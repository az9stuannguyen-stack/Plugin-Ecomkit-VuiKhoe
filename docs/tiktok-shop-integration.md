# TikTok Shop Vietnam — WP.6L.1 foundation

Audit date: 2026-10-07. Plugin 0.7.33 → 0.7.34, starting HEAD cfd92de. TikTok Shop seller integration only; not Login Kit, social, Research or creator integration. No production provider calls, OAuth, token operations, Order client, finance, reconciliation or canonical writes are implemented.

## Official contract audit and evidence limits

Only official Partner Center sources are used. Some direct document reads return a JavaScript shell. Indexed official documentation establishes the contracts below, but does not provide a complete current Vietnam Order Detail reference or token expiry specification. These remain explicitly unverified. No executable Order/token/signing behavior is based on those gaps.

| Operation | Verified contract / remaining questions | Official source |
|---|---|---|
| Seller authorization | ROW entry `https://services.tiktokshop.com/open/authorize`; seller entry uses `service_id`. Redirect URL is configured in Partner Center; callback returns `code`. State must be added by Ecomkit even where optional for the provider. Not an OpenAPI resource, no resource version/pagination. | [Create your App](https://partner.us.tiktokshop.com/docv2/page/create-your-app), [Authorization guide](https://partner.tiktokshop.com/docv2/page/678e3a362dccb8030ea6f98c) |
| Get Access Token | `GET https://auth.tiktok-shops.com/api/v2/token/get`; query `app_key`, `app_secret`, `auth_code`, `grant_type=authorized_code`. v2 token surface; no OpenAPI signing/header/pagination specified in the retrieved guide. Exact seller expiry fields/units/lifetimes must be verified before implementation. Shared token endpoint is documented; the retrieved detailed guide also covers creators, whose identity checks must NOT be copied into seller handling. | [Create your App](https://partner.us.tiktokshop.com/docv2/page/create-your-app), [Token flow](https://partner.tiktokshop.com/docv2/page/678e3a362dccb8030ea6f98c) |
| Refresh Access Token | `GET https://auth.tiktok-shops.com/api/v2/token/refresh`; query `app_key`, `app_secret`, `refresh_token`, `grant_type=refresh_token`. Rotation/expiry must be checked against actual seller contract before WP.6L.2. | [Token flow](https://partner.tiktokshop.com/docv2/page/678e3a362dccb8030ea6f98c) |
| Get Authorized Shops | Official versioning example establishes `GET /authorization/202309/shops`. Common signed query/header conventions apply. Full endpoint-specific required scope, response fields and pagination not retrieved; verify before executable client. | [API versioning](https://partner.tiktokshop.com/docv2/page/api-versioning), [Common parameters](https://partner.tiktokshop.com/docv2/page/678e3a4278f4c20311b8b57e) |
| Get Order List | Verified baseline `POST /order/202309/orders/search`, `seller.order.info`, header `x-tts-access-token`, JSON content type. Query requires `app_key`, `sign`, `timestamp`, `shop_cipher`, `page_size` (1–100); `page_token` uses prior `next_page_token`. Optional sort and JSON filters include create/update time bounds and order status. Not proof that 202309 is the latest available version. | [Get Order List 202309](https://partner.tiktokshop.com/docv2/page/get-order-list-202309) |
| Get Order Detail | Current official release note links a 202507 reference, but its method/path/required business parameters/Vietnam applicability were not readable in this audit. UNKNOWN until direct reference is verified; no guessed 202309/202507 endpoint is hardcoded. | [Official reference linked by release note](https://partner.tiktokshop.com/docv2/page/get-order-detail-202507), [Release note](https://partner.tiktokshop.com/docv2/page/lnhl0afk) |

OpenAPI base is `https://open-api.tiktokglobalshop.com`. Common query is `app_key`, `sign`, `timestamp` in seconds; version 202309+ uses `x-tts-access-token` rather than a query token, and JSON bodies use `application/json`. Shop-scoped requests use the documented `shop_cipher`. Do not infer shop identity from display name or email. These conventions are documented in [Common parameters](https://partner.tiktokshop.com/docv2/page/678e3a4278f4c20311b8b57e).

Official [signing example](https://partner.tiktokshop.com/docv2/page/wfi3nz36) excludes `sign` and `access_token`, sorts parameter names, concatenates path plus names/values, appends exact body bytes except multipart, wraps with App Secret and computes HMAC-SHA256 using App Secret, emitting hexadecimal. No signer is implemented here: independent official fixtures must be verified before WP.6L.2. Lazada/Shopee signers are not reused.

## Implemented architecture

`TIKTOK` joins the generic string platform validator. Existing DB9 marketplace connection identity `(platform, external_shop_id)` supports multiple shops. Existing `create_pending()` can reserve a real known external identity with `PENDING_AUTH`, null token credentials; saving application configuration creates no invented shop row and never marks ACTIVE.

TikTok configuration uses its own option and AES-256-GCM credential envelope, with provider-specific authenticated context. App Key, App Secret and Service ID are stored together in the encrypted envelope. Blank secret edit preserves it byte-for-byte; identity changes require intentional secret replacement. Safe UI projection excludes secret/token data. Shopee/Lazada options are untouched.

Marketplace card and save action require existing `manage_options`; configuration changes also require `ecomkit_tiktok_nonce`. Editor, Shop Manager and lower roles cannot render the card or use the endpoint. The stable HTTPS-only reserved callback is `admin-post.php?action=ecomkit_tiktok_oauth_callback`; its foundation handler denies use and does not read callback codes. It is not an operational authorization callback yet. No anonymous OAuth callback is registered.

`Ecomkit_Vuikhoe_Tiktok_Http_Client` is an inert boundary: requests fail locally with `TIKTOK_CLIENT_NOT_IMPLEMENTED`. Reserved error names cover auth, HTTP, provider, invalid response and network errors; no provider code mapping exists. Future implementation must use TLS verification, bounded timeout/response size, exact signed/sent bytes, JSON bodies and safe allowlisted request_id/error evidence. Never log full URLs, tokens, secrets, signatures, authorization codes or buyer payloads. Token endpoint secrets in documented server-side query transport must never enter browser/UI/logs.

## Excel and canonical audit

Existing combined identity parser recognizes exactly `Shopee` and `Lazada`. `TikTok`, `TikTok Shop`, `TIKTOK` currently return `EXCEL_UNKNOWN_PLATFORM`. No business Excel fixture establishes an approved TikTok label, so no speculative alias is introduced. A later verified parser extension must preserve exact string IDs in the existing `orders.marketplace_order_id`, without numeric conversion or a separate TikTok column. Schema and generic order ID storage already support strings; no migration is needed. Canonical v9 and all 24 columns remain unchanged.

## WP.6L.2 authorization security requirements

Use cryptographically random, single-use, expiring state bound to administrator, session, provider and configuration fingerprint. Validate callback/state/code server-side before token exchange. Never log authorization code. Validate seller identity and required granted scopes; encrypt tokens, apply refresh rotation atomically and handle reauthorization without corrupting an existing working connection. Call Get Authorized Shops after authorization and verify exact shop ID/cipher/region before READY. IDs and ciphers remain strings; names are display only. Verify expiry semantics from the full current seller reference before implementing lifecycle.

## Stages and verification

WP.6L.1 foundation → WP.6L.2 seller authorization/token lifecycle/authorized shops → WP.6L.3 Order client/live validation → WP.6L.4 exact reconciliation → WP.6L.5 financial source audit → WP.6L.6 proven financial mapping → WP.6L.7 one-upload pipeline.

Fake fixtures verify encryption, isolation, blank/replacement edits, pending multi-shop state, actual card rendering, permission/nonce boundaries, HTTPS callback and current parser behavior. Existing regression suites cover roles, Shopee, Lazada, PDF, clipboard and 24 columns. All automated provider calls remain zero. Manual deployment checks configuration/card only; do not attempt seller authorization in this stage.

Lazada WP.6J.6 remains paused pending Unmask approval. WP.7 is not started.
