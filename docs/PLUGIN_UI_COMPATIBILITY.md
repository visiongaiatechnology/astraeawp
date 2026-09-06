# AstraeaOS WP — Plugin UI Compatibility Matrix

> **Doctrine:** Non-Destructive Ecosystem Coexistence  
> **Ecosystem:** AstraeaOS / VisionGaiaTechnology

---

## 1. The Three Render Modes

AstraeaOS implements a triple-tier compatibility architecture to guarantee that 100% of WordPress plugins function seamlessly:

### 1. Astraea Native
- Core administrative interfaces and first-party Astraea modules (`ControlCenter`, `GeDefense HUD`, `Site Health`, `ComponentPreview`).
- Renders directly in full Layer 1/2/3 obsidian glass surfaces with native token styling.

### 2. Astraea Adapted
- Standard WordPress Core screens that utilize standard WordPress UI hooks (`edit.php`, `post-new.php`, `upload.php`, `plugins.php`, `options-general.php`).
- Automatically styled with glass tables, tokenized typography, modern focus indicators, and sleek buttons without rewriting plugin markup.

### 3. Legacy Compatibility
- Complex third-party plugin interfaces that introduce bespoke HTML, custom jQuery UI dialogs, or their own CSS frameworks (e.g., WooCommerce, Elementor, Advanced Custom Fields, Yoast SEO).
- Rendered safely inside a dedicated scoped canvas container (`.astraea-legacy-canvas`). Astraea applies strictly scoped rules and avoids aggressive resets (e.g. no unscoped `div {}` or `table {}` rules), guaranteeing zero layout breakage.

---

## 2. Plugin Menu Preservation

The `NavigationAdapter` reads `$GLOBALS['menu']` and `$GLOBALS['submenu']` after all plugin hooks have executed. Any slug not explicitly identified as core or Astraea is classified under `EXTENSIONS`, ensuring third-party menus are never suppressed, hidden, or misplaced.
