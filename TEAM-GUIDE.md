# RW Image Manager — Team Guide

How to audit and populate missing image details on a client site, and how the
system works when it doesn't behave.

**Target: a full audit and a first batch of approved metadata in under 30
minutes on a site you've never seen.**

---

## Why this exists

Every accessibility and SEO audit we run flags missing alt text. Filling it in
by hand is hours of work, and the third-party tools that automate it —
AltText.ai, ShortPixel, Alt Magic — all generate from the image alone plus a
keyword. **None of them know which page an image appears on.**

That matters. The same roofing panel photo means something different on a
product page than in a case study, and alt text that ignores the page it lives
on is generic by construction. This plugin builds the missing link — a reverse
map of every image to every page, template and custom field that references it
— and feeds that context to the model along with the picture.

---

## The 30-minute path

### 0. Before you start (one time per site)

1. **Update the plugin** to 2.0.0+ via **Dashboard → Updates** (it self-updates
   from GitHub).
2. **Add an API key.** *RW Image Manager → Image SEO → Anthropic API Key.*
   Paste, save. Stored in its own option, never sent to the browser.
   Without a key everything except generation still works — the audit, the
   duplicate review, and broken-image detection all run fine.

### 1. Build the usage index — ~1 min

*Image SEO → Step 1 → Build index.*

Maps every image to everything that references it. **Generation is meaningless
without this** — skip it and every image looks unused. Rebuild after a big
content import.

### 2. Scan the library — ~1 min

*Step 2 → Load chart.* Fingerprints files for duplicate detection, then
classifies everything. Cached afterwards, so it's ~2s on later visits. Use
**Rescan** after uploading or deleting images.

### 3. Read the chart before spending anything — 5 min

Four independent filters:

| Filter | Use it for |
|---|---|
| **Images** | On the site (default) · Decorative/unsupported · Not on any page · All |
| **Review** | Unreviewed (default) · Reviewed · All |
| **Proposals** | All · Current proposals only |
| **Metadata** | Missing any field · Missing alt text · Missing description · Nothing missing |

**Start with Metadata → Missing alt text.** That's the actual job. On the
pilot site it was 484 of 720 usable images.

Note that **Missing any field** counts an empty description as missing. With
Description off by default, images whose only gap is a description never clear
that filter — 34 of them on the pilot site. That is the filter reporting what
is genuinely empty, not a bug; use *Missing alt text* as your working view and
treat *Missing any field* as an inventory rather than a to-do list.

Each row shows every page the image appears on, whether it's that page's
featured image, and which fields are currently empty.

### 4. Generate a small batch first — 5 min

Select 15–20 rows, then *Step 3 → Generate*. **Read the output before scaling
up.** The cost estimate above the button tells you what a run will cost before
you commit.

**Tick which fields you want** beside the Generate button. Title and Alt text
are on by default; Description is off. An unticked field is not requested from
the model at all — it is absent from the schema and from the prompt, so it
costs nothing and the existing value on the image is left alone.

Description is off by default because it is the attachment's `post_content`,
which surfaces on the attachment page template most themes never link to and
in a few lightbox plugins. On a typical site it is never rendered in the DOM.
The field that *does* render under an image is the caption (`post_excerpt`),
which is a separate field this plugin does not write. Turn Description on if
your theme or a gallery plugin actually displays it — otherwise it is output
tokens spent on something nobody sees. Measured on the pilot site, adding it
costs about 55% more per image.

Two things worth setting once, under **Advanced AI settings**:

- **Extra instructions** — house vocabulary. *"This is a metal roofing
  supplier. Prefer product names like Standing Seam, Board & Batten. Never
  guess a colour you cannot clearly see."* This is the single highest-leverage
  knob.
- **Source weighting** — how much the model should lean on the picture vs. the
  page context vs. existing metadata. A source set to **0 is genuinely omitted
  from the request**, which is a real cost saving and a real behaviour change.

### 5. Review and apply — 10 min

Nothing is written until you press **Apply selected**. Every field is editable
first. An empty field is left alone rather than cleared.

- **Apply** marks the image reviewed automatically.
- **Reject proposal** drops the suggestion and leaves the image unreviewed —
  it still needs a decision.
- Reviewed images drop out of the default view, so progress is visible.

Writes only the fields you ticked: alt → `_wp_attachment_image_alt`,
title → `post_title`, description → `post_content`. A field that was not
generated is left exactly as it was — applying a Title + Alt text proposal
never touches an existing description.

---

## The other two tabs

### Duplicate review (read-only)

Files are hashed during the scan; byte-identical copies fold into one row, so
you generate once and Apply writes to every copy.

The panel shows each copy side by side with **its own** references — not the
group's merged references — because deciding which copy is redundant needs to
show that one copy is on four pages and the other on none.

**Read the keeper suggestion sceptically.** It scores metadata completeness
and ignores usage entirely. On the pilot site the suggestion disagreed with
actual usage in **8 of 16 groups** — the copy with the best alt text was
frequently the one no page referenced. Conflicts are flagged in red. Nothing
in this panel deletes anything; removal is done from each attachment's own
edit screen, deliberately.

### Broken images

Finds two faults WordPress never reports: references to attachments that have
been deleted, and references to files missing from disk.

**Not every broken reference is a broken image.** Pages render the image
*URL*, not the attachment ID, so a deleted attachment whose file is still on
disk looks perfectly fine. References on saved templates may never render, and
`*_tablet` / `*_mobile` variants only appear at those breakpoints. Rows are
rated on that basis — and because the rating is a guess, **every row has an
"Is it actually broken?" button** that loads the live page and reports whether
the file is genuinely requested. Trust that over the badge.

Repair is one reference at a time, previewed before writing, never bulk. It
handles all three storage shapes: post markup, Elementor JSON, ACF fields.

---

## Things that will bite you

**Images that keep breaking after you re-select them.** Something is renaming
files underneath the pages. Two known causes:

- *Image optimisation plugins that convert format on upload* (Elementor's
  Image Optimization, ShortPixel, Imagify). They rewrite `_wp_attached_file`;
  the URL already stored in Elementor or post content does not follow.
- *This plugin's own bulk tools*, before 2.0.0. **Bulk Resize** and **Remove
  -scaled Images** moved the file and repointed the media library but not the
  pages. Fixed in 2.0.0 — both now rewrite references before deleting
  anything. If a site was cleaned up with an older version, expect breakage
  that predates the fix.

**AVIF libraries.** If the host can't decode a format, the browser converts it
via canvas and posts it back. No host configuration needed. On a site whose
optimiser keeps pre-conversion originals, the plugin reads those instead —
cheaper and better quality.

**SVGs and icons are listed but never generated for** unless you explicitly
override per image. Prose alt text on a decorative mark is a correctness bug,
not a missing feature.

**Cost.** Roughly **$0.11 per 100 images** on the default model with the
default fields (Title + Alt text) — about **$0.17** if you also tick
Description. A 700-image library is well under a dollar either way. Model and
cost-per-100 are both shown in settings and update with your field choice.

---

## Validation status

Piloted on two sites with deliberately different stacks:

| | Site 1 | Site 2 |
|---|---|---|
| Builder | Elementor + Elementor Pro | Divi |
| Commerce | none | WooCommerce |
| SEO | Yoast | Rank Math |
| Image format | AVIF, converted on upload | standard |

Site 2 was verified independently, on a separate stack from the one the
plugin was developed against. Both Yoast and Rank Math focus keywords feed the
prompt; WooCommerce product galleries are indexed via `_product_image_gallery`.

**One caveat on Divi, worth knowing even though it passed.** There is no
Divi-specific extractor. Elementor gets a dedicated tree walker that
understands its media-control shape; Divi coverage comes from the generic
passes — `wp-image-N` classes, `<img src>` URLs, and uploads URLs resolved
back to attachments out of `post_content`, which is where Divi stores its
shortcodes. That covers `[et_pb_image src="…"]` and the like, which is most of
Divi in practice and why the pilot worked. What it cannot see is an image
referenced *only* by ID in a module attribute, with no URL alongside it —
there's nothing in the content to resolve. So on a Divi site, treat a "not
found on any page" verdict as needing a spot-check rather than as fact,
particularly before deleting anything on the strength of it.

---

## Still to build

Ordered by how much it matters.

1. **Duplicate merge.** The review panel is read-only. The hash index
   (`_ism_file_hash`) and the repoint engine both exist and were built for
   this, so merge is mostly wiring: pick a keeper, repoint every reference,
   trash the losers with an undo. Deliberately not shipped without an undo
   path — merge is the one genuinely destructive operation here.
2. **A Divi walker**, if we take on more Divi sites. Same shape as the
   Elementor one: find the module attributes that hold media, walk them,
   record ID and URL together.
3. **Keeper suggestion that considers usage.** Currently metadata-only, which
   is right for generation and wrong for deletion. Should weight references
   first, metadata as the tie-break. Blocks merge being safe.
4. **A Rosewood-hosted API proxy.** One key for the agency instead of one per
   site. Acknowledged as better; explicitly out of scope for 2.0.0.
5. **Auto-generation on upload.** Deliberately deferred — unreviewed AI output
   landing on a client page is the failure mode this whole design engineers
   against.
6. **A cross-site dashboard.** Audit status across all client sites at once.
7. **Perceptual duplicate matching.** The current hash is exact, so a
   re-saved or resized copy of the same photo is not detected. Exact matching
   was chosen deliberately: a false positive would write one image's alt text
   onto another. Any fuzzy matching needs a review step.

### Known limitations

- **Bare attachment IDs are unrecoverable.** ACF and gallery shortcodes store
  only an ID. Once the attachment is deleted there's no filename left to match
  on — those need the manual picker.
- **Match suggestions compare filenames, not pictures.** Two photos from one
  shoot share most of their tokens. Always open both before accepting.
- **The scan cache goes stale** after uploads or deletions. Rescan.
- **Generation quality varies by model.** The cheapest model is noticeably
  less reliable on fine colour distinctions — which matters on a site where
  colour *is* the product variant. Step up a tier for product photography.

---

## If something goes wrong

- **Nothing writes without an explicit click.** No auto-apply anywhere.
- **Proposals survive a reload.** They're stored server-side; you won't lose a
  paid-for run by closing the tab.
- **Bulk repoint operations snapshot before-state** into an option before
  writing, so a bad repair can be reversed.
- **Generation failures are per image.** One malformed response costs that
  image, not the run.
