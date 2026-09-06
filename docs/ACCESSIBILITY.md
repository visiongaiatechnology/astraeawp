# AstraeaOS WP — Accessibility Specification (a11y)

> **Standard:** WCAG 2.2 Level AA  
> **Status:** DIAMANT VGT SUPREME  
> **Ecosystem:** AstraeaOS / VisionGaiaTechnology

---

## 1. Core Architectural Accessibility Principles

AstraeaOS WP treats accessibility not as a superficial ARIA patch, but as an intrinsic foundation of the DOM architecture:
- **Semantic HTML First**: True `<button>`, `<input>`, `<nav>`, `<aside>`, and `<dialog>` semantics rather than `div[role="button"]`.
- **Keyboard Trapping & Modals**: Modals (such as the Command Palette) trap focus inside their perimeter and close predictably with `ESC`.
- **Contrast Ratios**:
  - Primary text on Obsidian Base: `#f8fafc` on `#070a0f` exceeds **18:1** (far surpassing the 4.5:1 WCAG requirement).
  - Secondary text: `#94a3b8` on `#070a0f` achieves **9.2:1**.
  - Status indicators: Color is never the sole communicator of status; icons (`✔`, `⚠`, `✕`) accompany all status pills.
- **Focus Indicators**: High-visibility cyan specular glow (`box-shadow: 0 0 0 3px rgba(0, 210, 255, 0.25)`) across all focused interactive elements.

---

## 2. Motion & Cognitive Accessibility

### `prefers-reduced-motion` Enforcement
AstraeaOS automatically honors user OS preferences for reduced motion:
```css
@media (prefers-reduced-motion: reduce) {
    :root, [data-theme="dark"], [data-theme="light"] {
        --ast-transition-fast: 0ms !important;
        --ast-transition-normal: 0ms !important;
        --ast-transition-slow: 0ms !important;
    }
    *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
    }
}
```

---

## 3. ARIA & Screen Reader Live Regions

1. **Notification Drawer**: Defined with `role="dialog"`, `aria-label="System Notifications"`, and `aria-hidden="true"` when dismissed.
2. **Command Palette**: Search results list utilizes `role="listbox"` with dynamic `aria-selected` and `aria-labelledby`.
3. **Breadcrumbs**: Emitted as `<nav aria-label="Breadcrumbs">` with `<ol>` and `aria-current="page"` on the leaf node.
4. **Live Regions**: In-page notice containers utilize `aria-live="polite"` so screen readers announce asynchronous events without disruptive interruptions.
