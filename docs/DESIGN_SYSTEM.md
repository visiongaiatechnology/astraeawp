# AstraeaOS WP — Glass Design System

> **Standard:** VisionGaiaTechnology Design Token System v1.0  
> **Aesthetic Archetype:** Astraea Glass (High-End Technical, Cyber-Obsidian, Cyan Accents)

---

## 1. Color Foundations & Palettes

Astraea Glass avoids rainbow gaming RGB clichés. It utilizes a restrained, deep space obsidian foundation accented with metallic electric cyan and specular highlights.

### Dark Mode (Primary)
- **Base Background**: `#070a0f` (`--astraea-bg-base`)
- **Secondary Surface**: `#0b0f17` (`--astraea-bg-secondary`)
- **Tertiary Surface**: `#111827` (`--astraea-bg-tertiary`)
- **Brand Accent (Electric Cyan)**: `#00d2ff` (`--astraea-color-primary`)
- **Brand Accent Hover**: `#38bdf8` (`--astraea-color-primary-hover`)
- **Brand Accent Dim**: `rgba(0, 210, 255, 0.12)` (`--astraea-color-primary-dim`)
- **Success (Emerald)**: `#10b981`
- **Warning (Amber)**: `#f59e0b`
- **Danger (Ruby)**: `#ef4444`

### Light Mode
- **Base Background**: `#f8fafc`
- **Surface**: `rgba(255, 255, 255, 0.85)`
- **Accent**: `#0284c7`

---

## 2. Multi-Level Glass Surfaces

Astraea Glass utilizes 3 calibrated levels of translucent depth:

| Level | Background RGBA | Backdrop Filter | Border | Primary Use Case |
| :--- | :--- | :--- | :--- | :--- |
| **L1 Surface** | `rgba(17, 24, 39, 0.55)` | `blur(12px)` | `1px solid rgba(255,255,255,0.06)` | Dashboard cards, table containers, notices |
| **L2 Surface** | `rgba(11, 15, 23, 0.75)` | `blur(20px)` | `1px solid rgba(255,255,255,0.08)` | Topbar, sidebar, banners |
| **L3 Surface** | `rgba(7, 10, 15, 0.88)` | `blur(32px)` | `1px solid rgba(0,210,255,0.25)` | Command palette modal, flyout submenus, drawer |

### Specular Edge Lighting
Surfaces incorporate a subtle top specular gradient highlight (`::before` pseudo-element with `linear-gradient(90deg, transparent, rgba(255,255,255,0.12), transparent)`), evoking high-end machined glass and aerospace displays.

---

## 3. Official Astraea Emblem

- **Source Asset**: `astraea-core/AdminUI/assets/img/astraea-logo.png` (mirror at `wp-admin/images/astraea-logo.png`).
- **Characteristics**: Cyan-metallic circular emblem with central chevron glyph.
- **Display Locations**:
  1. Topbar brand badge (`24x24px` with subtle cyan glow)
  2. Mission Control Center banner (`64x64px` with radial specular halo)
  3. Authentication portal `/wp-login.php` (`84x84px` with floating ambient drop-shadow)
