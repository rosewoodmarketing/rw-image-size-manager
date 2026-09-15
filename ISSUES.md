# Known Issues

Notes from a review done while debugging a critical error on the West Bench
Home Furnishings site. Review before committing/pushing, since this plugin is
installed on multiple sites.

## Fixed (uncommitted in working tree)

### PHP 8-only syntax breaks every site running PHP 7.4

`image-size-manager.php:804` declared:

```php
function ism_big_image_threshold( int $threshold ): int|false {
```

Union return types (`int|false`) require PHP 8.0+. West Bench Home
Furnishings runs PHP 7.4.33, where this is a fatal parse error - and because
it's a parse error (not a runtime error), it breaks on every request that has
to compile this file fresh (a new PHP-FPM worker, an opcache reset/reload, a
plain CLI invocation like `wp` or `php -l`). Live traffic on a warmed opcache
can keep serving cached bytecode from before the error was introduced, which
is why the front end can look fine right up until something invalidates the
cache - at which point the whole site goes down, not just this plugin.

**Fix applied:** dropped the return-type declaration, moved it to a `@return`
docblock instead. Identical behavior, valid on PHP 7.4+.

**Action before shipping:** confirm which PHP version each site running this
plugin is on, and add a PHP 7.4 lint step (`php -l` under the actual runtime,
not just the dev machine's PHP) to catch this class of bug before it reaches
any site again.

## Not fixed - needs a decision, not just a patch

### GitHub auto-updater has no release integrity verification

`includes/class-ism-github-updater.php` checks GitHub's releases API and, on
update, downloads `$release->zipball_url` and installs it directly - there's
no checksum or signature check against the downloaded zip. Anyone who can
push a tagged release to `rosewoodmarketing/rw-image-size-manager` (a
compromised GitHub account or a bad commit that slips through) can ship code
that this updater will offer to every site running the plugin.

Currently mitigated by:
- Auto-updates are off by default (WordPress core setting, per-site) - an
  admin has to click "Update Now," it doesn't install silently.
- The above PHP 7.4 bug is a real example of what "no review before release"
  already let through, even without any malicious intent involved.

Worth deciding on a fix before relying on this for more sites: e.g. a
required PR review before tagging a release, a lightweight CI lint/test step
against the plugin's minimum supported PHP version, or (bigger lift) signing
releases and verifying the signature in `after_install()`.

### Access token passed as a URL query string

Same file, `check_update()`:

```php
$download_url = add_query_arg( 'access_token', $this->access_token, $download_url );
```

If this plugin's repo is ever made private and a token is configured, that
token would be appended to the zip download URL — which can end up in web
server / proxy access logs. Currently inert (no token is configured for the
public repo), but should move to an `Authorization` header (like
`get_release()` already does correctly) before this repo is ever made
private or a token is added.
