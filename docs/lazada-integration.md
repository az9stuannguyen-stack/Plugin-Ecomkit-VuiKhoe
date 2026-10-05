# Lazada integration foundation — WP.6J.1

Plugin 0.7.17, schema 9, canonical v9. No migration, dependency, extension, environment variable or scheduled service added. No provider call, OAuth exchange, Order/Financial API, reconciliation or automatic Lazada processing is implemented.

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

Country `vn` is centralized in Lazada_Config. API/auth routes are intentionally null placeholders, not guessed endpoints. The configuration card is not OAuth readiness validation and never says CONNECTED. No account is inserted merely by saving app configuration. Generic create_pending accepts a real known external identity, leaves credentials null and status PENDING_AUTH; no fake shop/token. Multiple accounts may coexist under the existing unique key.

## Future credential / matching contracts

The app payload is v1 (`app_key`, `app_secret`, `country`). Existing encrypted connection envelope can hold future official authorization fields `access_token`, `refresh_token`, `expires_in`, `refresh_expires_in`, `account_id`, `country_user_info_list` after OAuth implementation verifies their semantics. This is a contract declaration, not fabricated token data. Future connection AAD must bind platform, country and actual account identity; app config AAD is separate from Shopee. No expiry durations are assumed here.

Future matching: Excel marketplace_order_id === official Lazada order ID, exact string identity after existing outer whitespace handling. No PII/fuzzy matching; no financial/status mapping inferred from Shopee.

Official references: [App parameters](https://open.lazada.com/apps/doc/doc?docId=108055&nodeId=10433), [Seller authorization](https://open.lazada.com/apps/doc/doc?docId=108260&nodeId=10533), [Token response contract](https://open.lazada.com/apps/doc/api?path=%2Fauth%2Ftoken%2Frefresh). Endpoint and lifecycle implementation belongs to WP.6J.2. WP.7 remains gated on completed live Lazada integration.
