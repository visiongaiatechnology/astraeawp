# AstraeaOS WP — Admin Compatibility Guarantees

> **Architecture:** Zero-Breakage Ecosystem Compatibility  
> **Target:** Third-Party Plugins, Custom Post Types, Classic Meta Boxes, Gutenberg

---

## 1. Core Principles

While AstraeaOS WP deeply modernizes the appearance and layout of the admin area, it **never breaks standard WordPress hooks, DOM semantics, or global variables**.

### Compatibility Matrix

| WordPress Subsystem | Mechanism in AstraeaOS | Compatibility Result |
| :--- | :--- | :--- |
| `add_menu_page()` / `add_submenu_page()` | `NavigationAdapter` intercepts `$GLOBALS['menu']` and categorizes items | 100% Compatible; custom pages appear in categorized menus or `EXTENSIONS` |
| `admin_notices` | `NoticeCenter` wraps notices into a glass card container & mirrors to drawer | 100% Compatible; notices are styled without breaking dismiss triggers or scripts |
| `wp-list-table` | `astraea-tables.css` restyles table elements via class selectors | 100% Compatible; column headers, bulk actions, and pagination work identically |
| Block Editor (Gutenberg) | `astraea-gutenberg.css` harmonizes top toolbar and inspector panels | 100% Compatible; zero disruptions to React block layout or inner block styling |
| Classic Meta Boxes | Standard post edit screens retain full DOM integrity | 100% Compatible; sortable boxes, toggle switches, and fields function normally |

---

## 2. Branding Eradication Without Broken Links

All references to WordPress are eliminated via official WordPress filter mechanisms:
- `admin_title`: Appends `— AstraeaOS` instead of `— WordPress`.
- `admin_footer_text`: Emits AstraeaOS WP and VisionGaiaTechnology credits.
- `update_footer`: Suppresses upstream WordPress version checks and displays system build telemetry.
- `admin_bar_menu`: Removes `wp-logo` node and replaces it with `astraea-brand` and `astraea-hud-badge`.
- `login_headerurl` & `login_headertext`: Rewritten to home URL and AstraeaOS WP authentication.
