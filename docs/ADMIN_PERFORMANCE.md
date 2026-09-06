# AstraeaOS WP — Admin UI Performance & Budgets

> **Standard:** VGT Zero-Bloat Performance Discipline  
> **Release Target:** Phase 2 (Glass Admin Experience)

---

## 1. Asset Payload & Budget Compliance

AstraeaOS strictly rejects external runtime CDNs, multi-megabyte JavaScript frameworks, and heavy CSS bloat.

### Payload Breakdown

| Asset | File | Size (Raw) | Size (Gzip/Brotli) | External CDN Requests |
| :--- | :--- | :--- | :--- | :--- |
| **Tokens** | `astraea-tokens.css` | ~5.8 KB | ~1.4 KB | **0** |
| **Glass Architecture** | `astraea-glass.css` | ~3.8 KB | ~1.1 KB | **0** |
| **Shell & Navigation** | `astraea-shell.css` | ~7.2 KB | ~2.1 KB | **0** |
| **Components & Forms** | `astraea-components.css` | ~5.6 KB | ~1.8 KB | **0** |
| **Data Tables** | `astraea-tables.css` | ~4.2 KB | ~1.3 KB | **0** |
| **Dashboard HUD** | `astraea-dashboard.css` | ~6.4 KB | ~1.9 KB | **0** |
| **Command Palette** | `astraea-command-palette.css` | ~3.9 KB | ~1.2 KB | **0** |
| **Notifications** | `astraea-notifications.css` | ~3.2 KB | ~1.1 KB | **0** |
| **Vanilla Engine JS** | `astraea-admin.js` | ~11.2 KB | ~3.2 KB | **0** |
| **Total Admin Bundle** | Combined Phase 2 Assets | **~51 KB** | **~14 KB** | **0** |

---

## 2. Rendering & Runtime Efficiency

1. **Backdrop Filter Throttling**:
   - Glass blur effects are strictly limited to fixed surfaces (Topbar, Sidebar, Modals, Drawer).
   - Scrollable content surfaces (table rows, list items) use lightweight opacity backgrounds without nested blur filters, avoiding composite layer thrashing and GPU battery drain on mobile devices.
2. **Debounced AJAX Dispatching**:
   - The Command Palette uses a 150ms debounce timer with local caching to prevent unnecessary database hits on every keystroke.
3. **No Heavy Third-Party Dependencies**:
   - Zero React runtime overhead for standard screens.
   - Zero jQuery dependencies in the new Astraea engine.
   - Zero remote font requests (relies on native high-performance system typography).
