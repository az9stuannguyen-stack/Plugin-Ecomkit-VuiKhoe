# Ecomkit admin UI — WP.6K.2

Plugin 0.7.33 uses one scoped admin stylesheet (`assets/css/admin.css`). It loads only on Ecomkit pages and uses the plugin version for cache busting. System fonts, centralized colors, white cards, consistent controls, visible keyboard focus and responsive layouts require no external framework or fonts.

Normal workflow: upload Excel → wait for processing → open Result → copy data. The native file input, processing controls, filters, clipboard and temporary PDF contracts remain unchanged. Result keeps all 24 canonical columns in their existing order, with horizontal scrolling, NULL displayed as — and explicit zero displayed as 0. Masked customer data remains neutral.

Dashboard uses only existing system data; no new metrics are inferred. Process emphasizes upload. Result separates copy actions, optional temporary PDF enrichment and collapsed source details. Errors and History retain their evidence/data. Marketplace and Settings group existing administrator controls into cards. Advanced technical tools remain collapsed and administrator-only.

Access remains WP.6K.1: Administrator has full access; Editor and Shop Manager have normal operational pages; lower roles have no access unless they independently have an approved capability. No role provisioning, permission, API, parser, finance, reconciliation, schema or canonical changes are introduced.

## Verification

`tests/admin-ui-render-check.php` renders the actual PHP templates with synthetic data and checks the scoped asset and Result contract. `tests/admin-ui-layout-check.js` renders eight states in Chromium at 1366 and 1920 pixels, checks viewport containment and Result scrolling, and saves screenshots in the temporary `ecomkit-ui-6k2` directory. These fixtures use a simulated WordPress shell, not a deployed WordPress installation, and make zero provider calls. Screenshots are inspected separately from automated layout assertions.

After deployment, hard refresh and review Dashboard, Process, Result, Errors and History as Administrator, Editor and Shop Manager. Verify upload, progress, filters, copying, temporary PDF and all 24 columns. Administrator also checks Marketplace and Settings. Confirm lower roles still cannot access Ecomkit. DB remains 9, canonical remains v9, migration NONE.

Lazada financial mapping remains paused pending Unmask approval. This stage does not start WP.6J.6 or WP.7.
