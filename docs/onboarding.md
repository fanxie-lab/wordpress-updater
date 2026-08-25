# Add a new theme or plugin

This walks through wiring a new theme or plugin into an update service you
already run (see [`docs/deploy.md`](deploy.md) if you haven't deployed one
yet). It expands the checklist in `docs/spec.md` §10.1 into copy-pasteable
steps, using the worked example throughout the repo: namespace `stilotex`,
update host `wp-updates.fanxie.cloud`.

Everything here happens in your **client repo** — the repo that holds the
theme or plugin itself, not this one.

## 1. Pick the namespace and get an upload token

One namespace per client or project (`stilotex`, not `stilotex-core` and
`stilotex-theme` separately — a namespace's client answers for every package
under it). If this namespace doesn't have an upload token yet, get one
issued: [`docs/deploy.md`](deploy.md) §5.

## 2. Add the library to the client repo

```bash
composer config repositories.fx-updates vcs https://github.com/fanxielab/fanxie-wp-updates
composer require fanxielab/wp-update-client
```

This installs the `FanxieLab\WpUpdates` client library (and `bin/release.php`,
`bin/build.sh`) into `vendor/fanxielab/wp-update-client/`.

## 3. Generate a signing keypair

Create the file the public key gets compiled into — the template is the
trailing comment block in
[`docs/examples/companion-plugin.php`](examples/companion-plugin.php):

```php
<?php
namespace Stilotex;
final class UpdateKey {
	public const COMPILED = '';
}
```

Save that as `src/UpdateKey.php` in the client repo (any path works; this is
the convention the example uses), then run:

```bash
php vendor/fanxielab/wp-update-client/bin/release.php keygen --write-to=src/UpdateKey.php
```

This patches the `COMPILED` constant in place and prints the secret key
once, to your terminal. Commit the file with the public key now filled in.
Paste the secret key into a new GitHub Actions repository secret named
`STILOTEX_UPDATE_SIGNING_KEY`, then clear your scrollback. **The secret is
printed once and cannot be recovered** — if you lose it before saving it,
run `keygen` again and get a new keypair.

## 4. Add the upload token secret

Add a second GitHub Actions secret, `STILOTEX_UPDATE_UPLOAD_TOKEN`, holding
the bearer token issued in step 1 (the same value you gave
`wrangler secret put UPLOAD_TOKEN_STILOTEX` when deploying).

## 5. Add the `Update URI` header

Every package this client answers for needs an `Update URI` header pointing
at its manifest path (the `.json` suffix is optional — the header is an
identifier, and the client derives the manifest URL from it):

```
Theme style.css:   Update URI: https://wp-updates.fanxie.cloud/stilotex/theme-stilotex
Plugin main file:  Update URI: https://wp-updates.fanxie.cloud/stilotex/plugin-stilotex-core
```

**Shared-hostname warning:** WordPress derives its update filter name from
the Update URI's *host* only — every namespace on this service shares the
same two filters (`update_themes_wp-updates.fanxie.cloud` and
`update_plugins_wp-updates.fanxie.cloud`). The client library disambiguates
by the full path (namespace + package), so this is safe as long as you don't
hand-roll your own filter callback that only checks the hostname.

## 6. Register the client

In the plugin (or theme's `functions.php`), on `init`:

```php
use FanxieLab\WpUpdates\Package;
use FanxieLab\WpUpdates\PackageType;
use FanxieLab\WpUpdates\UpdateClient;
use FanxieLab\WpUpdates\UpdateConfig;

add_action(
	'init',
	static function (): void {
		UpdateClient::register(
			new UpdateConfig(
				host: 'wp-updates.fanxie.cloud',
				namespace: 'stilotex',
				public_key: Stilotex\UpdateKey::COMPILED,
				hook_prefix: 'stilotex',
				packages: array(
					new Package( PackageType::Plugin, 'stilotex-core', 'stilotex-core/stilotex-core.php' ),
					new Package( PackageType::Theme, 'stilotex', 'stilotex' ),
				),
			)
		);
	}
);
```

The full, runnable version of this — with the plugin header and the
`UpdateKey.php` template — is
[`docs/examples/companion-plugin.php`](examples/companion-plugin.php). One
client per namespace answers for every package listed, so a plugin and its
companion theme (or several plugins) can share a single registration.

## 7. Add the release workflow

Copy [`docs/examples/client-release.yml`](examples/client-release.yml) into
your client repo at `.github/workflows/release.yml`, and adjust:

- the four `resolve` job values (slug, source directory, main file, per
  package type you build)
- `namespace:` in the `publish` job
- `key_file:` — the `src/UpdateKey.php` path from step 3
- the two secret names, if you used something other than
  `STILOTEX_UPDATE_SIGNING_KEY` / `STILOTEX_UPDATE_UPLOAD_TOKEN`

This calls the reusable workflow at
`fanxielab/fanxie-wp-updates/.github/workflows/release.yml@main`, which does
the actual build/sign/publish (`docs/spec.md` §6). If you forked this
service under a different GitHub org, that `uses:` line needs updating too.

## 8. Dry-run

Trigger the workflow manually (`workflow_dispatch` in the GitHub Actions
UI, or `gh workflow run release.yml`) with a scratch version like `0.0.1`.
This builds, signs and verifies the manifest exactly as a real release
would, but **stops before publishing anything** (`publish: false` on the
`workflow_dispatch` path).

Download the run's build artifact and inspect the ZIP:

```bash
unzip -l stilotex-core-0.0.1.zip | head
```

Confirm it has exactly one top-level directory, named exactly for the slug
(`stilotex-core/…`) — an archive whose inner directory is
`stilotex-core-0.0.1` installs *beside* the live package on a site and
updates nothing, a failure with no visible symptom until someone checks.

## 9. Verify on a test site

This step is not optional and not assumed — the whole point is to *observe*
an update being offered and taken, on a real site, once:

1. Install the current build of the theme/plugin on a scratch WordPress
   site (WordPress ≥ 6.1, PHP ≥ 8.2 with `sodium`).
2. Push a scratch release tag from the client repo, e.g. `core-v0.0.1`
   (or whatever your workflow's tag pattern matches), and let it publish for
   real this time.
3. On the test site:

```bash
wp transient delete update_plugins --network
wp plugin list --update=available
```

(or visit **Dashboard → Updates** in wp-admin)

**Confirm the update is offered, then take it**, and confirm the site ends
up on the new version. If nothing appears, see the troubleshooting section
below before assuming the pipeline is broken — the most common cause is a
stale negative cache from an earlier failed attempt.

## 10. Troubleshooting

- **Nothing is offered, and you don't know why.** Hook the refusal action —
  `add_action( 'stilotex_update_refused', function ( $message, $context ) { ... } )`
  — every refusal fires it, whether or not `WP_DEBUG` is on.
- **With `WP_DEBUG` enabled**, every refusal also writes a line to the PHP
  error log prefixed `[stilotex/update]`.
- **The negative cache holds a failure for about 15 minutes.** If you just
  fixed something (a bad manifest, a token, a DNS issue) and want to retry
  immediately rather than wait, delete the site transients:

```bash
wp transient delete stilotex_upd_plugin_stilotex-core
wp transient delete stilotex_upd_theme_stilotex
```

  (the pattern is `{hook_prefix}_upd_{type}_{slug}`)

- **If `api.wordpress.org` is unreachable, core never reaches third-party
  `Update URI` packages at all** — `wp_update_plugins()` /
  `wp_update_themes()` return before the Update URI loop runs unless
  wordpress.org answers first (`docs/spec.md` §5.11). This isn't fixable
  from inside the client; it only shows up as "nothing is offered" with no
  refusal logged, because the client's filters never ran.

## 11. Key rotation

Signing-key rotation is a manual release, not a service operation
(`docs/spec.md` §4) — there's no revocation list or second key slot yet:

1. Generate a new keypair (`bin/release.php keygen`).
2. Commit the new public half into the client build.
3. Deliver that build to every affected site by hand — SFTP break-glass, not
   the update pipeline (the old key is what the pipeline still verifies
   against until the new build is live).
4. Resume tagging normally once every site is confirmed on the new key.
