# VLP (VisionLegalPro) Light Edition

**AstraeaOS WP 0.4.0-alpha** integrates VLP Light as a first-party privacy control plane under `astraea-core/VLP/Light`.

## Architecture

- **ConsentManager** issues an HttpOnly, SameSite=Lax, policy-versioned consent receipt authenticated with HMAC-SHA256 using a domain-separated Astraea Keyring subkey (`VLP_CONSENT`).
- **DomGatekeeper** transforms optional third-party resource tags using WordPress `WP_HTML_Tag_Processor` before the response reaches the browser. Known inline trackers are made inert before execution.
- **Browser gate** synchronously protects dynamic DOM insertion/property setters and common network APIs (`fetch`, XHR, Beacon, WebSocket, EventSource), plus external CSS URL insertion paths.
- **ServiceRegistry** is editable by administrators after current-session Astraea Step-Up reauthentication. Unknown third-party URLs fail closed into the `functional` category.
- **ScannerService** scans the rendered home response, observable Set-Cookie headers, plugin code and the active theme using fixed roots, `realpath()` jails, symlink rejection, extension allowlists and bounded file/response sizes.
- **Dattrack Light** only accepts events after explicit statistics consent. It uses same-origin validation, a request nonce, rate limiting, a daily rotating HMAC visitor identifier and Astraea AEAD encryption under the `VLP_DATTRACK` domain. Raw IP addresses are never stored.

## Consent categories

`necessary`, `functional`, `statistics`, `marketing`, `external_media`.

Necessary is always active. All optional categories default to denied.

## Cache safety

Strict Cache Safety is enabled by default. Consent-dependent front-end responses are emitted with no-cache headers and `Vary: Cookie` to avoid shared-cache state confusion. Operators can disable strict mode only when their cache architecture is explicitly consent-neutral.

## Security boundary

VLP Light controls browser-side resources and client-created cookies. It cannot retroactively prevent a PHP component from emitting an HTTP `Set-Cookie` header before VLP can intervene. The scanner surfaces observable server-set cookies so the originating component can be made consent-aware. VLP Light makes no certification or automatic legal-compliance claim.

## Zero dependency

No runtime CDN, third-party JavaScript SDK, Composer package, npm package or external analytics service is required.
