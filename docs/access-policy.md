# WP.6K.1 — Ecomkit access (0.7.32)

Central policy: `can_use_ecomkit()` accepts manage_options OR edit_pages OR manage_woocommerce. `can_manage_ecomkit()` accepts manage_options only. Menus use an approved native capability the current user has; callbacks independently enforce authorization.

| User | Normal workflow | Marketplace/Settings/technical tools |
| --- | --- | --- |
| Administrator | Yes | Yes |
| Editor | Yes | No |
| Shop Manager | Yes | No |
| Author/Contributor/Subscriber without approved capabilities | No | No |
| Legacy Ecomkit Operator with only old custom capabilities | No | No |

Multiple roles are evaluated by capabilities, never role names. No role provisioning, mutation, custom capability grants, reactivation or user recreation. Legacy roles are left untouched.

Normal pages: Dashboard, Process, Result, Errors, History. Normal actions: Excel import and existing automatic processing, progress polling, temporary PDF upload. Clipboard/header copy, clearing PDF and filtering remain browser operations on authorized Result data. Shared company historical data remains visible.

Admin-only: Marketplace/Settings, credentials/configuration, OAuth initiation/token controls, provider diagnostics, manual reconciliation, financial maintenance, runtime tests, Batch state export, manual pipeline resume/materialization. Advanced Process/Result controls are conditional server-side. Normal result reopening/polling and automatic processing do not require those buttons.

Nonces remain unchanged. Unauthorized callbacks deny before data reads/business calls. Public provider OAuth callbacks retain existing state/session verification.

WordPress reference: https://developer.wordpress.org/reference/functions/user_can_access_admin_page/ and https://developer.wordpress.org/reference/functions/add_management_page/.

Live test: deploy 0.7.32, verify all six role cases, upload a known Excel as Editor/Shop Manager, and confirm direct Marketplace/Settings URLs are denied. DB 9, canonical v9, no migration or business changes. Lazada finance stays paused pending Unmask approval. Do not start WP.6J.6 or WP.7.
