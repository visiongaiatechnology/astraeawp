# GeDefense Scanner Evidence Triage

**Distribution:** AstraeaOS WP `0.6.0-alpha`

## Classification model

GeDefense scanner findings are not synonymous with malware. Every finding carries an explicit evidence class:

- `MALWARE` — executable/active evidence strong enough to enter blocking/quarantine policy.
- `SUSPICIOUS` — anomalous content requiring review; never malware solely from its numeric score.
- `POLICY` — unsafe deployment/location/configuration condition, such as executable content under uploads, without claiming malicious intent.
- `INFO` — non-blocking context or metadata.

`VIS_Scan_Verdict::shouldBlock()` and quarantine eligibility require `MALWARE` evidence. A high risk percentage on a `POLICY`, `SUSPICIOUS`, or `INFO` finding cannot independently block a file.

## Context-aware detectors

### Paths

Transient executable detection is restricted to actual runtime/transient roots. Source-code namespaces such as `astraea-core/Vault/src/Backup/` do not become transient merely because a directory is named `Backup`. Minimal deny/exit `index.php` files in upload-protection directories are recognized as inert guards. Other executable files under uploads remain a high-severity policy finding and can still become blocking when a content detector independently finds malware evidence.

### PHP and JavaScript

The PHP lexical detector uses tokens with comments and string/heredoc literals removed before request-to-exec analysis. JavaScript embedded-PHP detection tracks comments and single/double/template-string state. Security regression fixtures and documentation strings therefore do not become executable evidence, while real constructs such as request data reaching `eval`, `system`, shell execution, file droppers or active embedded PHP remain detectable.

### SVG/XML

DOCTYPE metadata alone is informational. XML/SVG parse failure is review evidence, not malware. Active script elements, event-handler attributes, `javascript:` URLs, dangerous active data URLs and external entity payloads remain malware evidence.

## Regression corpus

The scanner regression suite includes the previously observed false-positive classes:

- Astraea Vault `src/Backup/*.php`;
- protected upload `index.php` guards;
- WordPress `wp-includes/js/customize-loader.js`;
- WordPress VIPS worker bundle;
- TinyMCE legacy SVG font assets;
- Astraea/WordPress dashboard SVG;
- GeDefense tests containing malicious examples as inert strings.

The same suite contains positive controls for request-to-exec PHP, upload webshells, embedded active PHP and active SVG scripts so precision fixes cannot silently disable actual malware detection.
