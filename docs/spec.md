# wp-updates.fanxie.cloud — multi-tenant WordPress update service

Core logic for a self-hosted theme/plugin update service on Cloudflare, serving
David's sites and client sites. Extracted and generalized from this repo's
proven single-site pipeline (`docs/plan.md` Phase 2, `docs/adr/0004`,
`plugins/dp-core/src/Update/`), which shipped and was observed working
end-to-end against WordPress 7.1.

**Status: extraction/spec.** This document describes the target system; the
single-site implementation in this repo is the reference.

**Decided (2026-08-24):** the system lives in its own repo (David sets it up;
its README must carry onboarding instructions for adding a new theme or plugin
— see §10.1); **all artifacts live in R2** — manifests *and* package ZIPs, no
GitHub Releases on the update path; manifest paths are
`{namespace}/{type}-{slug}.json`; writes go through a **write Worker** with
per-namespace bearer tokens (§10.4). No open decisions.

---

## 1. What it is

- One host, `wp-updates.fanxie.cloud`, serving **signed update manifests** for
  every theme and plugin we maintain, namespaced per client.
- WordPress core's own third-party update filters
  (`update_themes_{$hostname}` / `update_plugins_{$hostname}`, native since
  5.8/6.1) consume the manifests. **No updater plugin** on any site — a small
  update client (~450 lines of PHP, libsodium only) ships inside each package
  or inside a per-site companion plugin.
- A **git tag is the whole deploy**: tag → CI gates → build ZIP → upload ZIP
  to R2 → sign manifest → upload manifest to R2 → sites auto-update on their
  next cron run.

## 2. URL and namespace scheme

```
https://wp-updates.fanxie.cloud/{namespace}/theme-{slug}.json
https://wp-updates.fanxie.cloud/{namespace}/plugin-{slug}.json
```

- `{namespace}` is one client/project: `stilotex`, `dpaternina`, `fanxie`, …
- Examples:
  - `https://wp-updates.fanxie.cloud/stilotex/theme-stilotex.json`
  - `https://wp-updates.fanxie.cloud/dpaternina/plugin-dp-core.json`
- Each package's `Update URI` header points at its manifest path (without
  `.json` is fine — the header is an identifier; the client derives the
  manifest URL from it):

```
Theme style.css:   Update URI: https://wp-updates.fanxie.cloud/stilotex/theme-stilotex
Plugin main file:  Update URI: https://wp-updates.fanxie.cloud/stilotex/plugin-stilotex-core
```

**Shared-hostname consequence (load-bearing):** WordPress derives the filter
name from the Update URI *host* only. Every namespace therefore shares the same
two filters: `update_themes_wp-updates.fanxie.cloud` and
`update_plugins_wp-updates.fanxie.cloud`. The update client MUST disambiguate
by the full Update URI path (namespace + package), not just by hostname, and
MUST return `false` for any package whose Update URI it doesn't own — two of
our packages on one site must not answer for each other.

Package ZIPs live under the namespace too:

```
https://wp-updates.fanxie.cloud/{namespace}/packages/{type}-{slug}-{version}.zip
```

## 3. Manifest format — a signed envelope

Each manifest file is an envelope; the signature covers the exact payload
bytes:

```json
{
  "schema": 1,
  "payload": "<base64 of the manifest JSON>",
  "signature": "<base64 detached Ed25519 over the raw payload bytes>"
}
```

The decoded payload:

```json
{
  "type": "theme",
  "slug": "stilotex",
  "version": "1.4.2",
  "requires": "6.5",
  "requires_php": "8.2",
  "package": "https://…/theme-stilotex-1.4.2.zip",
  "tested": "6.7"
}
```

Why base64-in-an-envelope rather than a sibling `signature` field: verifying a
sibling field means re-serializing parsed JSON to recover the signed bytes, and
any signer/verifier disagreement about key order, unicode escaping, or float
formatting becomes a false rejection or — worse — a signature that covers
something other than what was parsed. With an opaque payload, the bytes
verified and the bytes parsed are provably identical. (A detached `.sig` file
has the same property but costs a second HTTP request per check.)

## 4. Trust model and keys

- **Ed25519, one keypair per namespace.** A client-site compromise or key leak
  is contained to that namespace; rotating one client's key doesn't touch the
  others.
- The **public key is compiled into** the update client shipped to that
  client's site — a class constant, **not filterable, not stored in the DB**.
  A trust anchor any plugin or one `wp_options` write can replace is not a
  trust anchor. The only override is a `wp-config.php` constant (implies
  filesystem access; exists so staging can trust a staging key).
- Secret keys live only in GitHub Actions secrets (one per repo/namespace).
- **Empty key = every update refused and logged.** The build script refuses to
  produce a release ZIP while the compiled key is empty, so a keyless build
  can't reach a site.
- Key rotation is a manual release: generate a new pair, commit the new public
  half, deliver that build to affected sites by hand (SFTP break-glass), then
  resume tagging. No revocation list, no second key slot until the fleet size
  justifies them.

## 5. Update client — core logic

The client hooks four filters and fails closed everywhere. Behaviors below were
verified against WordPress 7.1 source, not documentation (`docs/adr/0004`):

1. **`update_themes_wp-updates.fanxie.cloud` / `update_plugins_wp-updates.fanxie.cloud`**
   — fetch the envelope for the package's manifest URL, verify with
   `sodium_crypto_sign_verify_detached()` against the compiled public key,
   return the offer as an object with `id`, `version` (as `new_version`),
   `url`, `package`.
2. **The filters take four arguments** (`$update, $data, $file, $locales`) —
   the fourth is installed locales for translation packages. Accept what you
   use, but know the arity.
3. **Return every verified manifest; let core compare versions.** Core runs
   `version_compare()` itself and files non-newer offers under
   `no_update` — which is what populates the "Auto-updates enabled" UI.
   Suppressing non-newer manifests breaks that UI for nothing.
4. **Theme offers MUST set `theme` explicitly.** Core forces
   `$update->plugin` for plugins but forces nothing extra for themes; a theme
   offer without `theme` is accepted into the transient and then **fails at
   install time** — the single most expensive silent mistake on this path.
5. **Themes are stored in the transient as arrays, plugins as objects.**
   Anything reading those transients must know which it holds.
6. **Fail closed, never pass through.** Every path that doesn't end in a
   verified signature returns `false` — including when a previous callback
   already put something in `$update`. We own this hostname; passing a
   stranger's value through hands the upgrader a package URL under our name.
   Refusals fire an action (`…_update_refused`) and `error_log()` under
   `WP_DEBUG`.
7. **Defense in depth beyond the signature:** package URL must be HTTPS on
   the update host, inside the package's own namespace; manifest
   `slug`/`type` must match the package being checked; version must
   be plain semver; the manifest URL is derived from the `Update URI` header
   only when that header's host — and for multi-tenant, its **namespace
   path** — matches what this build trusts.
8. **Cache the envelope, re-verify on every read.** The raw signed envelope is
   cached in a site transient (~6h) and the signature re-checked each read.
   Caching the *conclusion* would turn any writable object cache into an
   update-offer injection point. Failures are negative-cached (~15min) —
   update checks run on `admin_init`, and a down update host must not mean a
   blocking HTTP request per admin page load.
9. **Auto-update opt-in is scoped twice:** `auto_update_theme` /
   `auto_update_plugin` return `true` only when the offer's `id` is on our
   host **and** the item is one of our slugs; everything else — including
   `core`/`translation` offers and the `null` core uses to detect an unhooked
   filter — is returned untouched.
10. **Hooks are static array callables**, not closures — removable by a site
    owner, and duplicate registration is unrepresentable.
11. **Known core limitation:** if `api.wordpress.org` is unreachable, core
    returns before the Update URI loop runs — no third-party updates either.
    Not fixable from a filter.

**Packaging the client for many sites:** extract
`plugins/dp-core/src/Update/` (Host, Manifest, ManifestSource, PackageType,
PublicKey, UpdateClient, Verifier, Log) into a small reusable library,
parameterized by: update host, namespace, package slugs, compiled public key.
Ship it inside each client's companion plugin (or the theme, for theme-only
clients).

## 6. Release pipeline (per client repo)

Tags: `theme-v1.2.3` / `core-v1.2.3` (or `{plugin}-v1.2.3`), independent
versions. On tag push, GitHub Actions:

1. **Run the full CI gate suite** by *calling* the CI workflow
   (`uses: ./.github/workflows/ci.yml` + `workflow_call:`) — never by
   restating the gates, which drift.
2. **Stage, stamp, zip:** copy the package to `dist/stage/<slug>` (never dirty
   the working tree), stamp the version from the tag into headers and
   `readme.txt`, run `composer install --no-dev` inside the stage, prune dev
   files, zip with an **explicit single top-level directory named exactly
   `<slug>`** — an archive whose inner directory is `slug-1.2.3` installs
   *beside* the live package and updates nothing, a failure with no symptom.
3. **Upload the ZIP first** — through the write Worker (§10.4), authenticated
   with the namespace's bearer token, landing at
   `{namespace}/packages/{type}-{slug}-{version}.zip` — then `curl` the
   public package URL through `wp-updates.fanxie.cloud` to confirm it
   resolves, **then** sign and upload the manifest. A manifest naming a
   download that doesn't exist yet is an update offer that fails on every
   site that takes it. (A GitHub Release may still be created for archival,
   but nothing on the update path points at it.)
4. Sign the payload with the namespace's secret key
   (`{NAMESPACE}_UPDATE_SIGNING_KEY` secret), upload
   `{namespace}/{type}-{slug}.json` through the same Worker endpoint.
5. `workflow_dispatch` variant performs every step including signing and
   verification, and stops before publishing (dry run).

Publishing uses `curl` against the write Worker (plus `gh` if an archival
Release is kept) — no marketplace actions with repo write access on the
release path, and no cloud-storage credentials in client repos.

## 7. Cloudflare infrastructure

- **R2 bucket** (e.g. `wp-updates`), objects keyed
  `{namespace}/{type}-{slug}.json` and `{namespace}/packages/*.zip`.
- **Custom domain** `wp-updates.fanxie.cloud` attached to the bucket. Static
  serving is sufficient — manifests are public, tamper-evident via signature,
  and secrecy of a version number buys nothing.
- **Write Worker** (decided, §10.4): reads are direct from the bucket; all CI
  writes go through a small Worker with an R2 binding that authenticates a
  per-namespace bearer token and only accepts writes under that namespace's
  prefix.
- Optional Worker on the read path later (per-namespace stats, `latest`
  aliases, redirect maps); not required for the core flow.

Per-repo GitHub secrets: the namespace's signing secret key
(`{NAMESPACE}_UPDATE_SIGNING_KEY`) and its write-Worker bearer token
(`{NAMESPACE}_UPDATE_UPLOAD_TOKEN`). No R2 credentials ever leave the service
repo — the Worker holds the bucket binding.

## 8. What was considered and rejected (inherited rationale)

- **Git Updater plugin** — mature, but an extra plugin doing what ~450 lines
  of ours do, and a supply-chain surface on the one path where that surface is
  worst.
- **rsync/SSH from Actions** — bypasses the WordPress updater, no rollback;
  kept as the documented break-glass path (key rotation), not the primary.
- **Composer + Satis** — right only if `wp-content` were Composer-managed.
- **Signing the ZIP instead of the manifest** — WordPress 7.1 never
  signature-checks plugin/theme packages (`WP_Upgrader::run()` passes
  `false` to `download_package()`; only `Core_Upgrader` opts in), so the
  manifest signature is the only one core will act on — and the right one,
  since the manifest is what an attacker would tamper with. A `.sig` beside
  each ZIP verified via `upgrader_pre_download` is a later hardening step,
  not a substitute.

## 9. Testing (per client integration)

- Integration tests feed a fake manifest through the update filters and
  assert: good signature → update offer; bad signature → nothing, and a
  logged refusal; lower version → `no_update`, not `response`; theme offers
  carry `theme`.
- Tests must serve a plausible `api.wordpress.org` response first, or core
  bails before the third-party loop (see §5.11).
- Done-when, per client: tagging a scratch version results in a test site
  **offering and taking** the update — observed, not assumed.

## 10. Decisions

1. **Own repo — decided.** The service gets its own repository (David sets it
   up), holding: the reusable update-client PHP library, the reusable release
   workflow (`workflow_call`) client repos invoke, bucket/domain config, the
   write Worker (§10.4), and this document. Its README must
   include **onboarding instructions for a new theme or plugin**: create the
   namespace, run keygen, commit the public key into the client build, set the
   repo secrets, add the `Update URI` header, wire the release workflow, and
   verify with a scratch tag on a test site (§9).
2. **R2 for everything — decided.** Manifests and package ZIPs both live in
   the bucket under the namespace. Nothing on the update path points at
   GitHub; the client's allowed-host check is the update host only.
3. **Path shape — confirmed.** `{namespace}/{type}-{slug}.json`, packages at
   `{namespace}/packages/{type}-{slug}-{version}.zip`.
4. **Per-namespace write isolation — decided: write Worker.** Why isolation
   is needed at all: standing R2 API tokens scope to *buckets*, not prefixes,
   so a plain token in one client repo could overwrite every other client's
   manifests and ZIPs; a compromised repo holds only its own signing key, so
   the cross-tenant risk is denial of update rather than malicious code
   delivery — but that's still not acceptable once a client's CI is outside
   David's control. The Worker:
   - Reads stay direct from the bucket via the custom domain; the Worker
     handles **writes only** (e.g. `PUT wp-updates.fanxie.cloud/upload/…` or
     a separate hostname).
   - Holds the R2 bucket binding; authenticates a **per-namespace bearer
     token** (Worker secret) and accepts writes only under that token's
     namespace prefix.
   - Sanity-checks uploads: the envelope parses, the path matches the
     manifest's `type`/`slug`, ZIPs land under `packages/`.
   - Onboarding a client = issue one bearer token; rotating a client's
     credentials = rotate one secret. No R2 keys in any client repo.
   Alternatives rejected: bucket-per-namespace (a custom domain attaches to
   one bucket, so reads would need a routing Worker anyway, and each client
   adds bucket + binding + deploy) and a shared bucket-wide token (accepts
   cross-tenant denial-of-update; fine only while every namespace is
   David's own).
