# AstraeaOS WP — Admin UI Architecture

> **Ecosystem:** AstraeaOS / VisionGaiaTechnology  
> **Kernel Standard:** 2.1 | DIAMANT VGT SUPREME  
> **Release Target:** 0.1.0-alpha (Phase 2: Glass Admin Experience)

---

## 1. Executive Architectural Overview

The **Astraea Glass Admin Experience** transforms the legacy WordPress administration interface into a futuristic, technical, and high-performance operating system interface.

Unlike superficial cosmetic overlays or fragile DOM mutation scripts, the Astraea Admin UI is engineered as an **integrated, deterministic architecture**:
- **Categorical Navigation Engine (`NavigationAdapter`)**: Intelligently groups native core menus and third-party plugins into clean functional domains (`CONTENT`, `DESIGN`, `SYSTEM`, `ASTRAEA`, `EXTENSIONS`).
- **Glass Shell Renderer (`ShellRenderer`)**: Injects an obsidian glass topbar, real-time telemetry badges, theme switcher, and eliminates all legacy WordPress brandings (no `wp-logo`, no "Thank you for creating with WordPress", no upstream version leaks).
- **Command Kernel (`CommandRegistry`)**: Global `Ctrl+K` command palette with keyboard navigation, fuzzy search across screens, actions, and posts, with nonces and capability gates.
- **Control Center Dashboard (`ControlCenter`)**: Replaces the antiquated welcome panel with an interactive Mission Control Center HUD featuring real-time engine telemetry, GeDefense security status, and content activity pulses.
- **Notification Drawer (`NoticeCenter`)**: Gathers and consolidates in-page notices into an unobtrusive glass drawer, preventing disruptive layout shifts.
- **Authentication Portal (`LoginTheme`)**: Turns `/wp-login.php` into an obsidian glass portal with metallic cyan specular glows and the official Astraea emblem.
- **Offline-First & Zero Runtime CDNs**: All fonts, styles, scripts, and images reside strictly within `astraea-core/AdminUI/assets/`.

---

## 2. Directory Structure

```text
astraea-core/AdminUI/
├── AdminUIBootstrap.php               # Central coordinator hooking admin_init & enqueue
├── CommandPalette/
│   └── CommandRegistry.php            # AJAX/Search handler & modal markup
├── Dashboard/
│   └── ControlCenter.php              # Mission Control Center HUD & GeDefense telemetry screen
├── Login/
│   └── LoginTheme.php                 # /wp-login.php glass customization
├── Navigation/
│   └── NavigationAdapter.php          # Category mapper & breadcrumb generator
├── Notifications/
│   └── NoticeCenter.php               # Notice wrapper & drawer collection
├── Shell/
│   └── ShellRenderer.php              # Topbar, breadcrumbs, user badge, branding filters
└── assets/
    ├── css/
    │   ├── astraea-tokens.css         # CSS variables: spacing, radius, typography, colors
    │   ├── astraea-glass.css          # L1/L2/L3 glass surfaces, specular glows, fallbacks
    │   ├── astraea-shell.css          # Topbar, sidebar, submenus, footer
    │   ├── astraea-components.css     # Buttons, inputs, switches, modals, badges
    │   ├── astraea-tables.css         # Modernized wp-list-table, filters, pagination
    │   ├── astraea-dashboard.css      # Control Center HUD grid & telemetry rows
    │   ├── astraea-command-palette.css# Ctrl+K modal overlay styling
    │   ├── astraea-notifications.css  # Slide-out drawer & notice styling
    │   ├── astraea-login.css          # Authentication screen glass card & glow
    │   └── astraea-gutenberg.css      # Block editor harmonization
    ├── img/
    │   └── astraea-logo.png           # Official cyan-metallic Astraea emblem
    └── js/
        └── astraea-admin.js           # Vanilla ESNext modular engine
```

---

## 3. Security & VGT Doktrin Compliance

All UI components adhere strictly to **DIAMANT VGT SUPREME**:
1. **Capability Checks**: Every action, menu item, and search result verifies `current_user_can()` before execution or display.
2. **Cryptographic Nonces**: AJAX searches and drawer interactions enforce `wp_verify_nonce`.
3. **Escaped Markup**: Zero unescaped variables. Output uses `esc_html`, `esc_attr`, and `esc_url`.
4. **Input Sanitization**: User search inputs pass through `sanitize_text_field` and `wp_unslash`.
5. **No Runtime CDNs**: Zero requests to Google Fonts, external CDN stylesheets, or tracking endpoints.
