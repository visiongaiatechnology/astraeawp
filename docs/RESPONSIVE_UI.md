# AstraeaOS WP — Responsive UI Architecture

> **Breakpoints:** 320px, 375px, 768px, 1024px, 1440px, Ultrawide  
> **Ecosystem:** AstraeaOS / VisionGaiaTechnology

---

## 1. Breakpoint Grid & Adaptation Matrix

| Viewport | Device Class | Sidebar State | Topbar Layout | Tables Behavior |
| :--- | :--- | :--- | :--- | :--- |
| **< 768px** | Mobile Phones (320px–480px) | Off-canvas Drawer | Compact Brand, Search, Drawer trigger | Horizontal card rows / scrollable wrapper |
| **768px – 1024px** | Tablets / Small Laptops | Collapsed icon-rail (72px) | Breadcrumbs, Search, Quick Actions | Responsive columns, secondary hidden |
| **1024px – 1440px**| Standard Desktop | Full categorized sidebar (200px) | Full breadcrumbs, HUD, Profile, Search | Complete table grid with hover actions |
| **> 1440px** | High-Res / Ultrawide | Fixed sidebar, centered canvas | Complete HUD telemetry with quick tools | Extended table metrics, dual-pane panels |

---

## 2. Touch Interactivity & Minimum Hit Areas

- Interactive targets (`button`, `a`, input controls, drawer close buttons) are constrained to a minimum bounding box of **$44 \times 44$px** on touch viewports.
- Hover states are non-blocking: all essential operations (edit, delete, view, copy) remain accessible via tap/focus without requiring persistent hover capabilities.
