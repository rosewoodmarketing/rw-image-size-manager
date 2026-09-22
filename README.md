# RW Image Manager

**Version:** 2.2.0  
**Requires PHP:** 7.4+  
**Author:** Anthony Burkholder  
**License:** GPL-2.0+  
**Requires WordPress:** 6.0+  
**Tested up to:** 6.9  

A WordPress admin plugin for managing images across an entire site: image sizes and per-post-type allowlists, custom size registration, original-upload dimension limits, thumbnail regeneration, a media log, an orphaned-file scanner, and — from 2.0.0 — page-context-aware AI generation of image titles, alt text and descriptions, duplicate review, and broken-image detection and repair.

**Team workflow guide:** see [TEAM-GUIDE.md](TEAM-GUIDE.md) for the end-to-end process.

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

### Image Size Usage Scanner
- Scan all published content to identify which registered image sizes are actually referenced.
- Detects references in Gutenberg/block editor (`sizeSlug`), Classic Editor HTML (`size-*` CSS classes), gallery shortcodes, and Elementor widget data.
- Correctly detects Elementor's Image widget default size (`large`) even when Elementor omits the key from stored JSON.
- Classifies each size as **Core** (always needed), **In Use** (referenced in content), **Plugin** (WooCommerce — used in PHP templates), or **Unused** (no references found).
- Click any non-zero Total Refs count to expand an inline list of posts/pages where that size is used, with direct edit links and source badges (Content / Elementor).
- Includes explanatory notes on srcset fallback behaviour and PHP-template limitations.

### Bulk Image Tools (Advanced tab)
- **Resize Existing Images:** batch-resizes all images on disk to fit within the configured Max Upload Dimensions without regenerating all sizes.
- **Find & Remove -scaled Images:** removes WordPress `-scaled` backup files and repoints the media library to the originals; only enabled when the max upload dimension suppresses future `-scaled` creation.
- **Regenerate All Images:** batch-regenerates the entire media library using the current global settings and per-CPT rules where applicable, while deleting obsolete size files left behind by past configuration changes.

### Orphaned Files Scanner
- Scans the uploads directory and lists image files with no corresponding media library entry.
- Allows batch deletion of selected orphans with a path-traversal safety check.

---

## Installation

1. Upload the `rw-image-size-manager` folder to `wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **RW Image Manager** in the WordPress admin sidebar.
4. For AI generation only: add an Anthropic API key under **Image SEO**. Everything else works without one.

---

## Changelog

### 2.2.0
- **New feature:** Minimum and maximum character counts for generated titles, alt text and descriptions, set beside the field checkboxes on the Image SEO tab. 0 means no limit. Defaults match the previous guidance (title 60, alt text 125, description unlimited).
- The range is stated in the prompt and in the schema description (structured outputs reject `minLength`/`maxLength`), and every result is measured. A field that misses gets one automatic rewrite of that field only; anything still outside is flagged "length off" in review and never truncated. Apply asks before writing a flagged row.
- Review fields show a live character count against the range. Cost estimates account for configured maximums.
- An impossible range (minimum above maximum) is refused and blocks Generate when its field is ticked.
- **Selection:** the chart toolbar now has separate **Select all shown** (every row matching the current filters, across all pages) and **Deselect all** (clears every selection, including rows a filter is hiding). The selection count reports ticked rows hidden by the filter.
- **Bug fix:** the review card's Select all / Select none buttons did nothing. They now work as **Select all with changes** and **Deselect all**.

### 2.1.2
- **Bug fix:** "Show only these" now clears every filter that could hide a pending row, and the count beside it matches what is shown.

### 2.1.1
- **Improvement:** "The first N images without a proposal" draws from the current filtered view.

### 2.1.0
- **New feature:** Choose which fields generation writes. Title and alt text are on by default; description is off, since most themes never render it. Unticked fields are not requested and cost nothing.

### 2.0.0
- **Renamed** to RW Image Manager (display name only; slug, directory and `ism_` prefix unchanged so updates keep working).
- **New feature:** Image SEO tab. Page-context-aware AI generation of titles, alt text and descriptions via the Anthropic API, with a usage index mapping every image to the pages, templates and custom fields (ACF postmeta and termmeta) that reference it. Scan, generate, review and apply; nothing is written without an explicit click. The API key is optional; every other feature works without it.
- **New feature:** Advanced AI settings: extra instructions, source weighting, and a per-run cost estimate.
- **New feature:** Read-only duplicate review, grouping byte-identical copies.
- **New feature:** Broken Images tab: finds references to deleted attachments and missing files, triages likely-harmless leftovers, and repairs one reference at a time across post markup, Elementor data and ACF fields.
- **AVIF:** images the host cannot decode are converted in the browser; readable pre-conversion siblings are used when present.
- **Bug fix:** Bulk Resize and Remove -scaled Images now repoint page references before moving files, not only the media library.
- Team workflow guide added: [TEAM-GUIDE.md](TEAM-GUIDE.md).

### 1.3.0 — 2026-05-20
- **New feature:** Added **Regenerate All Images** in the Advanced tab. This processes the entire media library in batches, regenerates current sizes for every image, and deletes old thumbnail files that are no longer needed.
- **Improvement:** Library-wide regeneration now respects per-CPT image-size rules, including images related through direct attachment parents, featured images, and WooCommerce product galleries.
- **Bug fix:** Regeneration now removes orphaned old size files from disk even when earlier resize operations updated attachment metadata and left obsolete thumbnails untracked.
- **Bug fix:** Regenerate All resume state now restores the correct batch offset and deleted-file totals instead of restarting progress from zero.
- **Bug fix:** Bulk-tool cancel buttons now reset correctly between runs.
- **Hardening:** Added uploads-directory boundary checks before deleting regenerated thumbnail files, and tightened a couple of admin-side escaping/rendering paths in the usage scanner and uploads audit UI.

### 1.2.0 — 2026-04-17
- **New feature:** Image Size Usage Scanner — scans all published content and Elementor pages to classify each registered size as Core, In Use, Plugin (template), or Unused. Counts are deduplicated per-post (a post matching multiple patterns still counts as one reference). Click any count to expand an inline list of posts/pages using that size.
- **Bug fix (scanner):** Elementor's Image widget omits `image_size` from stored JSON when it equals its default (`large`). The scanner now uses a recursive JSON walker with per-widget-type defaults instead of regex, so default-size widgets are correctly detected.
- **Bug fix (scanner):** Elementor query previously pulled all `_elementor_data` rows regardless of post status, inflating the page count with revisions, trashed posts, and auto-drafts. Fixed with an `INNER JOIN` to `wp_posts` filtering to live post statuses only.
- **New feature:** Bulk Image Tools tab (renamed to Advanced tab) — groups Resize Existing Images and Find & Remove -scaled Images tools.
- **Infrastructure:** AJAX handler functions extracted from `image-size-manager.php` into `includes/ajax-handlers.php` for maintainability.

### 1.1.6 — 2026-03-14
- **UI:** Added a warning notice to the Orphaned Files tab advising users to test in a staging environment before running deletions.

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

- Disabling a size only prevents future generation. Existing files for that size are **not** deleted automatically — use the built-in regeneration tools after changing size settings.
- The Auto-Delete Images feature is **permanent and cannot be undone**. Images shared with other posts will also be deleted.
- The Orphaned Files scanner loads the full uploads directory into memory. On very large sites this operation may be slow.



Plugin updates workflow:
1. Make changes locally
2. Bump the version in image-size-manager.php (header + ISM_VERSION constant) and add a changelog entry to README.md
3. git add -A && git commit -m "vX.X.X – description" && git push
4. Publish a new release on GitHub tagged vX.X.X
5. All sites running the plugin will see the update in WP Admin → Updates automatically