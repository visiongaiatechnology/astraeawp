# AstraeaOS WP — Command Palette Specification

> **Feature:** Keyboard-First Command & Navigation Kernel  
> **Trigger:** `Ctrl + K` / `Cmd + K`  
> **Engine:** `astraea-core/AdminUI/CommandPalette/CommandRegistry.php` & `astraea-admin.js`

---

## 1. Interaction Model

The Astraea Command Palette provides instant access to all administrative actions, navigation menus, and resources without requiring pointer navigation.

### Keyboard Shortcuts
- **Open Modal**: `Ctrl + K` or `Cmd + K` (works from anywhere in the admin shell)
- **Traverse Results**: `ArrowDown` ($\downarrow$), `ArrowUp` ($\uparrow$)
- **Execute / Open**: `Enter` ($\crarr$)
- **Dismiss**: `Escape` (ESC) or click on backdrop
- **Sequential Navigation**:
  - `G` followed by `D`: Navigate immediately to Control Center Dashboard
  - `C` followed by `P`: Open New Post Editor

---

## 2. Live Search Pipeline

1. **Client Event**: Typing in `#astraea-cmd-input` triggers a debounced (150ms) fetch request.
2. **Endpoint**: `GET /wp-admin/admin-ajax.php?action=astraea_command_search&nonce=...&q=...`
3. **Backend Processing**:
   - `wp_verify_nonce` validates the CSRF token.
   - `current_user_can('read')` verifies basic authentication and permissions.
   - Matches against static system actions (Site Health, GeDefense HUD, New Media, View Site).
   - Matches against categorized admin menu slugs and titles.
   - If user possesses `edit_posts` capability and search string is provided, queries recent Posts/Pages matching `$q`.
4. **Response**: Sanitized JSON array of matching items with category, icon, URL, and keyboard hint.
