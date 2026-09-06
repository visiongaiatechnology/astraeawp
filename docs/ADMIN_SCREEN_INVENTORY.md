# AstraeaOS WP — Admin Screen Inventory

**Product:** AstraeaOS WP  
**UI Architecture:** Astraea Glass Admin Experience  
**Version:** `0.1.0-alpha`  
**Classification:** VGT Doktrin Kernel 2.1 | DIAMANT VGT SUPREME  

---

## 1. Categorization Model

All administrative interfaces within AstraeaOS WP are partitioned into four deterministic rendering modes to preserve ecosystem compatibility without compromising modern design integrity:

| Render Mode | Definition | Target Screens |
| :--- | :--- | :--- |
| **Astraea Native** | Complete bespoke Glass UI built from ground up. Replaces legacy templates entirely. | Dashboard (Control Center), Security HUD, System Health, Login Portal. |
| **Astraea Adapted** | Core WordPress APIs (List Tables, Form Rows, Meta Boxes, Settings API) seamlessly styled via Astraea Design Tokens and Glass containers. | Posts, Pages, Media, Comments, Plugins, Users, Settings, Tools, Profile. |
| **Legacy Compatibility** | Third-party plugin interfaces with arbitrary or inline legacy styling. Sandboxed inside the unified Astraea Glass Shell. | WooCommerce, Yoast, Advanced Custom Fields, Custom Plugin Pages. |
| **Fullscreen / Special** | Specialized full-viewport interactive environments. Astraea Shell respects immersive mode while providing persistent HUD backlink. | Gutenberg Block Editor, Site Editor (`site-editor.php`), Customizer. |

---

## 2. Exhaustive Core Screen Inventory

### 2.1 Content Domain
* **Posts List** (`/wp-admin/edit.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Floating Glass List Table, Sticky Filter Bar, Modern Row Actions, Status Chips, Search Input.
* **Add / Edit Post** (`/wp-admin/post-new.php`, `/wp-admin/post.php`):
  * *Mode:* Fullscreen / Special (Gutenberg) with Astraea Ambient Styling.
  * *Compatibility:* Preserves Block Editor canvas, block settings inspector, and meta boxes.
* **Pages List** (`/wp-admin/edit.php?post_type=page`):
  * *Mode:* Astraea Adapted
  * *Components:* Hierarchical Glass Table, Page Attributes, Quick Edit modal.
* **Media Library** (`/wp-admin/upload.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Grid View / List View switcher, Drag & Drop Upload Zone, Attachment Detail Glass Modal.
* **Comments Moderation** (`/wp-admin/edit-comments.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Glass Action Cards, Quick Reply, Inline Status Toggles, Spam/Trash Bulk Confirmation.

### 2.2 Design Domain
* **Themes Management** (`/wp-admin/themes.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Glass Theme Cards, Active Theme Highlight, Live Preview trigger.
* **Site Editor / FSE** (`/wp-admin/site-editor.php`):
  * *Mode:* Fullscreen / Special
  * *Components:* Immersive block theme editor with Astraea Topbar back-navigation.

### 2.3 System & Administration Domain
* **Plugins Directory** (`/wp-admin/plugins.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Segmented Glass Table (Active, Inactive, Updates), Auto-Update toggles, Action links.
* **Users Management** (`/wp-admin/users.php`, `user-new.php`, `profile.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Avatar integration, Role badges, Two-Factor / Session diagnostics, Password generation modal.
* **Settings Domain** (`options-general.php`, `options-writing.php`, etc.):
  * *Mode:* Astraea Adapted
  * *Components:* Tabbed Glass Settings Panels, Floating Save Bar, Input Validation indicators.
* **Tools Domain** (`tools.php`, `import.php`, `export.php`, `site-health.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Diagnostic health summary, Export/Import cards.
* **Core & Security Updates** (`update-core.php`):
  * *Mode:* Astraea Adapted
  * *Components:* Clean distinction between AstraeaOS Core, WordPress Upstream Baseline, Plugins, and Translations.

### 2.4 Astraea Native Core Surfaces
* **Control Center (Dashboard)** (`/wp-admin/index.php`):
  * *Mode:* **Astraea Native**
  * *Components:*
    * Real-time System Telemetry Panel (PHP 8.5, 64-bit, MySQL 8.0).
    * Live Content Metrics (Posts, Pages, Media, Comments, Users).
    * GeDefense Real-time Security HUD (Firewall state, L0 dropped packets, Aegis DPI alerts).
    * Performance Monitor (Active memory allocation, database queries, autoload hygiene).
* **GeDefense Security Center** (`/wp-admin/admin.php?page=astraea-security`):
  * *Mode:* **Astraea Native**
  * *Components:* Full RASP telemetrics, Cerberus L0 drop counters, Zeus 6G rules, IP threat scoring table.
* **System Health Monitor** (`/wp-admin/admin.php?page=astraea-health`):
  * *Mode:* **Astraea Native**
  * *Components:* Cryptographic status, Argon2id calibrations, Database index verifications.
* **Astraea Glass Login** (`/wp-login.php`):
  * *Mode:* **Astraea Native**
  * *Components:* Translucent Glassmorphism Card, Official AstraeaOS Cyan-Metallic Emblem, Dark/Light mode toggle.

---

## 3. Global Navigation Model

The Astraea Navigation Model parses all registered WordPress `$menu` and `$submenu` structures into four prioritized ergonomic categories:

```text
┌────────────────────────────────────────────────────────┐
│ ASTRAEAOS WP                                           │
├────────────────────────────────────────────────────────┤
│ ❖ CONTENT                                              │
│   ├── Dashboard (Control Center)                       │
│   ├── Posts                                            │
│   ├── Media                                            │
│   ├── Pages                                            │
│   └── Comments                                         │
│                                                        │
│ ❖ DESIGN                                               │
│   ├── Appearance                                       │
│   └── Editor                                           │
│                                                        │
│ ❖ SYSTEM                                               │
│   ├── Plugins                                          │
│   ├── Users                                            │
│   ├── Tools                                            │
│   └── Settings                                         │
│                                                        │
│ ❖ ASTRAEA CORE                                         │
│   ├── Security HUD (GeDefense)                         │
│   ├── System Health                                    │
│   └── Performance                                      │
│                                                        │
│ ❖ EXTENSIONS (Third-Party Plugins)                     │
│   └── [Dynamically Injected Plugin Menus]              │
└────────────────────────────────────────────────────────┘
```
