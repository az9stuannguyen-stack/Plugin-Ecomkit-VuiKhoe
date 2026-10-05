# Lazada seller authorization — WP.6J.2

Plugin 0.7.18, schema 9, canonical v9. No migration, dependency, extension, environment variable or scheduled service added. OAuth/token exchange and refresh are implemented; Order/Financial API, reconciliation and automatic Lazada order processing are NOT implemented. Automated tests use fake transport only. Live seller authorization remains unverified.

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

WP.6J.3 remains gated on live ACTIVE/READY authorization. WP.7 remains gated on completed live Lazada integration.
