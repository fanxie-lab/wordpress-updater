# fanxie-wp-updates

Self-hosted, signed WordPress theme/plugin updates for agencies and studios
maintaining packages across client sites — without a per-site updater plugin.
A git tag on a client repo is the whole release: it builds a ZIP, signs a
manifest, and pushes both to a Cloudflare R2 bucket behind one small Worker.
Sites pick the update up through WordPress core's own third-party update
filters (`update_themes_{$hostname}` / `update_plugins_{$hostname}`, native
since 5.8/6.1), verified with a compiled-in Ed25519 public key and a
~450-line PHP client — no plugin marketplace, no third-party updater, no
standing write credentials outside this service.

## How a release works

```
git tag theme-v1.4.2
   │
   ▼
client repo CI gates ──▶ stage → stamp → zip ──▶ PUT zip ─▶ Write Worker ─▶ R2
                                    │                          (per-namespace
                                    ▼                           bearer token)
                          sign manifest (Ed25519,
                          key only in GH secrets)
                                    │
                                    ▼
                              PUT manifest ─▶ Write Worker ─▶ R2
                                                               │
   site cron: wp_update_plugins() ◀── GET manifest.json ◀──────┘
   verify signature against compiled public key → offer → auto-update
```

The tag is the trigger; nothing downstream is manual. `bin/build.sh` stages
the package into a clean copy (never dirtying the working tree), stamps the
version, runs `composer install --no-dev` inside the stage, prunes dev files,
and zips it with exactly one top-level directory named for the slug.
`bin/release.php manifest` signs the payload with the namespace's secret key,
which exists only as a GitHub Actions secret. CI uploads the ZIP first,
confirms the public package URL resolves through the read domain, and only
then signs and uploads the manifest; the write Worker independently refuses
any manifest whose ZIP isn't already sitting in the bucket (`409`) — so a
manifest is never published pointing at a download that doesn't exist yet,
whether or not CI's own check ran.

## Security model

- **Ed25519, one keypair per namespace.** A client-site compromise or key
  leak is contained to that one namespace; rotating one client's key never
  touches another.
- **The public key is compiled into the build** — a PHP class constant, not
  filterable, not stored in the database. The only override is a
  `wp-config.php` constant, which implies filesystem access and exists so a
  staging environment can trust a staging key.
- **The signature covers the exact payload bytes.** Manifests are an
  envelope — `schema`, base64 `payload`, base64 `signature` — so the bytes
  verified and the bytes parsed are provably identical; there is no
  re-serialization step where a signer and a verifier could quietly
  disagree.
- **Fail closed, everywhere.** Every code path that doesn't end in a
  verified signature returns `false` — never a passthrough of whatever a
  previous callback already put there. An **empty compiled key means every
  update is refused and logged**; the build script itself refuses to
  produce a release ZIP with no key compiled in.
- **The signed envelope is cached, not the verdict.** The raw envelope sits
  in a short-lived site transient, but the signature is re-checked on every
  read — a writable object cache can't turn into an update-offer injection
  point by holding a stale "verified" conclusion.
- **Package URLs are pinned** to the update host and the requesting
  package's own namespace and slug; a manifest can't point an update at a
  download outside its own tenant.
- **The Worker checks shape and tenancy, not trust.** It validates that an
  upload looks like a manifest or a ZIP, that a namespace's bearer token
  only writes under that namespace's prefix, and that a package exists
  before the manifest naming it can be published. It does **not** check the
  Ed25519 signature — that trust boundary lives entirely on the WordPress
  sites, where the public key is compiled in.

See [`docs/spec.md`](docs/spec.md) §4–5 for the full design rationale.

## Repository layout

```
fanxie-wp-updates/
├── client/                 PHP update client library (FanxieLab\WpUpdates)
├── bin/                    release.php (keygen/zip/manifest/verify), build.sh
├── worker/                 Cloudflare Worker — the write path into R2
├── .github/workflows/      ci.yml, release.yml (reusable, workflow_call)
├── docs/
│   ├── spec.md              design rationale and full trust-model writeup
│   ├── deploy.md             deploy your own instance, from zero
│   ├── onboarding.md         add a new theme or plugin to an existing instance
│   └── examples/
│       ├── companion-plugin.php   canonical client registration snippet
│       └── client-release.yml     example client-repo release workflow
├── tests/                  Unit, Integration, fixtures
├── composer.json           fanxielab/wp-update-client
└── LICENSE
```

## Deploy your own

Setting up the Cloudflare side — bucket, custom domain, Worker, namespace
tokens — is covered end to end in [`docs/deploy.md`](docs/deploy.md).

## Add a package

Adding a new theme or plugin to an instance you already run — namespace,
signing key, `Update URI` header, release workflow, and the scratch-tag
verification that proves it actually works — is covered in
[`docs/onboarding.md`](docs/onboarding.md).

## Design rationale

The full design — URL and namespace scheme, manifest format, trust model,
release pipeline, and what was considered and rejected — is written up in
[`docs/spec.md`](docs/spec.md).

## Requirements

- WordPress ≥ 6.1 (theme/plugin update filters are native since 5.8/6.1)
- PHP ≥ 8.2 with the `sodium` extension, on every site running the client
- A Cloudflare account with R2 enabled, and a zone you control for the
  update and upload hostnames
- GitHub Actions for releases (the reusable workflow is `workflow_call`
  only — it isn't meant to run any other way)

## Status & provenance

This library was extracted and generalized from a single-site update
pipeline that shipped and was observed working end-to-end against
WordPress 7.1. See [`docs/spec.md`](docs/spec.md) for the full history and
the behaviors that were verified against WordPress core source rather than
documentation.

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

---

*Note for anyone forking this under a different GitHub organization:* the
examples and the reusable workflow above assume the `fanxielab` org. If
yours differs, update the `uses:` references in this README, in `docs/*`,
and the `repository:` line in
[`.github/workflows/release.yml`](.github/workflows/release.yml).
