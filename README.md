# RW Image Size Manager

**Version:** 1.1.5  
**Author:** Anthony Burkholder  
**License:** GPL-2.0+  
**Requires WordPress:** 6.0+  
**Tested up to:** 6.7  

A WordPress admin plugin for viewing, toggling, and customizing image sizes across the entire site — including per-post-type allowlists, custom size registration, original-upload dimension limits, thumbnail regeneration, a media log, and an orphaned-file scanner.

---

## Features

### Registered Sizes
- Toggle any registered image size (core, theme, or plugin-added) on or off globally.
- Override width, height, and crop settings for the four built-in WordPress sizes: `thumbnail`, `medium`, `medium_large`, and `large`.
- Disabled sizes are removed from editor dropdowns (block editor, classic editor, Elementor).

### Custom Sizes
- Define additional image sizes with a name (slug), width, height, and hard-crop option.
- Sizes are registered via `add_image_size()` on every page load and appear in all editor size pickers.

### Max Upload Dimensions
- Automatically resize uploaded originals in-place before WordPress generates any sub-sizes.
- Suppresses WordPress's built-in `-scaled` behaviour when active.
- Set width and/or height limits independently; set either to `0` to leave that axis unconstrained.

### Post Type Rules
- Per-CPT image size allowlists: restrict which sizes are generated when an image is uploaded to a post of that type.
- Auto-delete images: permanently delete all attached images when a post is permanently removed from trash (with a confirmation warning).
- WooCommerce support: works with `product` CPT including featured images and the product gallery.

### Thumbnail Regeneration
- Batch-regenerates all images for a selected post type using the current size settings.
- Deletes old size files that are no longer needed after regeneration.
- Supports pause/resume via `localStorage`; auto-retries failed requests (up to 3 times with exponential back-off).
- Recovers pre-scaled originals (WordPress `-scaled` backups) before regenerating.

### Media Log
- Browse all uploaded images with pagination and filename search.
- Shows each image's original dimensions, file size, parent post, upload date, and all generated size variants with existence and file-size checks.

### Orphaned Files Scanner
- Scans the uploads directory and lists image files with no corresponding media library entry.
- Allows batch deletion of selected orphans with a path-traversal safety check.

---

## Installation

1. Upload the `rw-image-size-manager` folder to `wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **Image Sizes** in the WordPress admin sidebar.

---

## Changelog

### 1.1.5 — 2026-03-14
- **Infrastructure:** Plugin source moved to GitHub (`rosewoodmarketing/rw-image-size-manager`). WordPress sites running this plugin will now receive automatic update notifications through the standard WP Admin Updates screen when new releases are published.

### 1.1.4 — 2026-03-09
- **Security:** Added HTML-escaping (`esc()`) to all server-supplied values interpolated into HTML strings in the Media Log and Orphaned Files admin UI, preventing potential stored XSS via crafted post titles, filenames, or relative paths.
- **Bug fix:** `ism_regen_attachment()` now captures `_wp_attached_file` before modifying it in Step 1 and restores it on any early-return error path, preventing attachments from being left in a dirty state if regeneration fails mid-run.

### 1.1.3
- Added per-CPT post type rules (image size restriction and auto-delete) as a replacement for the legacy WooCommerce-only settings.
- Added Thumbnail Regeneration UI with pause/resume support.
- Added Media Log tab.
- Added Orphaned Files scanner and bulk-delete.
- Added Max Upload Dimensions with suppression of WordPress `-scaled` behaviour.
- Auto-migrates legacy `woo_*` settings to `post_type_rules['product']`.

---

## Notes

- Disabling a size only prevents future generation. Existing files for that size are **not** deleted automatically — use the Thumbnail Regeneration tool after changing size settings.
- The Auto-Delete Images feature is **permanent and cannot be undone**. Images shared with other posts will also be deleted.
- The Orphaned Files scanner loads the full uploads directory into memory. On very large sites this operation may be slow.
