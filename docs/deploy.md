# Deploy your own instance

Everything below sets up the Cloudflare side of the service — the R2 bucket
that stores manifests and package ZIPs, the read domain that serves them, the
write Worker that CI publishes through, and the per-namespace tokens that
authenticate a client repo's release workflow. It assumes you're starting
from nothing on a zone you already control (the worked example throughout is
`fanxie.cloud`, with the service on `wp-updates.fanxie.cloud`).

Once this is running, adding your first theme or plugin is
[`docs/onboarding.md`](onboarding.md).

## 1. Prerequisites

- A Cloudflare account, with R2 enabled.
- A zone on Cloudflare that you control (e.g. `fanxie.cloud`) — the custom
  domains below (`wp-updates.fanxie.cloud`, `wp-updates-upload.fanxie.cloud`)
  are subdomains of it.
- Node 22+ (for `wrangler` and the Worker's own tooling).
- Logged in to Wrangler:

```bash
npx wrangler login
```

## 2. Create the bucket

```bash
npx wrangler r2 bucket create wp-updates
```

Objects in this bucket are keyed `{namespace}/{type}-{slug}.json` for
manifests and `{namespace}/packages/{type}-{slug}-{version}.zip` for
package ZIPs (`docs/spec.md` §2, §7).

## 3. Attach the read domain

In the Cloudflare dashboard: **R2 → wp-updates → Settings → Custom Domains**,
and add `wp-updates.fanxie.cloud`.

This serves `GET` requests directly from the bucket — no Worker in the read
path. That's deliberate: manifests are public and tamper-evident (every
manifest carries an Ed25519 signature that sites verify against a compiled-in
key), so static serving is sufficient and there's no read-side auth to build
or operate (`docs/spec.md` §7).

## 4. Deploy the write Worker

The Worker (`worker/`) is the only thing that can write into the bucket. If
your hostnames differ from the worked example, edit `worker/wrangler.jsonc`
first — in particular the `routes` custom domain and the `PUBLIC_HOST` var,
which the Worker uses to validate a manifest's `package` URL against the
read domain you set up in step 3.

```bash
cd worker
npm ci
npx wrangler deploy
```

The `routes` entry (`wp-updates-upload.fanxie.cloud`, `custom_domain: true`)
provisions the write hostname on deploy — no separate dashboard step.

**Why writes live on a separate hostname:** reads stay on the bucket's own
custom domain (step 3) and never touch application code. The Worker holds
the only path that can mutate the bucket — a per-namespace bearer token, a
handful of shape checks (does this look like a manifest or a ZIP, does the
namespace in the token match the path, does the package this manifest names
already exist), and nothing else. Keeping that logic on its own hostname
means a bug or an outage in the write path can never take reads down with it.

## 5. Issue a namespace token

Each namespace (one per client/project) gets its own bearer token, stored as
a Worker secret named `UPLOAD_TOKEN_{NAMESPACE}` — the namespace uppercased,
with `-` replaced by `_` (`worker/src/index.ts` derives this name from the
path at request time, so the naming rule isn't optional).

```bash
openssl rand -base64 32
```

```bash
npx wrangler secret put UPLOAD_TOKEN_STILOTEX
```

(paste the value from `openssl rand -base64 32` when prompted; run this from
inside `worker/`, or pass `--config worker/wrangler.jsonc` from the repo
root)

The same value becomes the client repo's GitHub Actions secret
`STILOTEX_UPDATE_UPLOAD_TOKEN` — see
[`docs/onboarding.md`](onboarding.md) §4.

## 6. Smoke-test

Unauthenticated write — refused (`401`, since the namespace is already
known but no bearer token was presented):

```bash
curl -i -X PUT \
  --data-binary '@/dev/null' \
  https://wp-updates-upload.fanxie.cloud/stilotex/packages/plugin-stilotex-core-0.0.1.zip
```

Authenticated write of a tiny real ZIP — the Worker only checks the first
four bytes are the ZIP local-file-header magic (`PK\x03\x04`), but build one
properly rather than faking the header:

```bash
UPLOAD_TOKEN_STILOTEX="<the token from step 5>"

mkdir -p /tmp/fx-smoke && echo hello > /tmp/fx-smoke/hello.txt
( cd /tmp/fx-smoke && zip -q /tmp/fx-smoke.zip hello.txt )

curl -i -X PUT \
  -H "Authorization: Bearer ${UPLOAD_TOKEN_STILOTEX}" \
  --data-binary '@/tmp/fx-smoke.zip' \
  https://wp-updates-upload.fanxie.cloud/stilotex/packages/plugin-stilotex-core-0.0.1.zip
```

Expect `200 {"ok":true,"key":"stilotex/packages/plugin-stilotex-core-0.0.1.zip"}`.

Read it back through the bucket's own custom domain:

```bash
curl -i https://wp-updates.fanxie.cloud/stilotex/packages/plugin-stilotex-core-0.0.1.zip
```

Expect `200` with the ZIP body.

(A manifest `PUT` to `https://wp-updates-upload.fanxie.cloud/stilotex/plugin-stilotex-core.json`
would additionally be refused with `409` unless a matching package ZIP is
already uploaded — see `docs/onboarding.md` for the full signed-manifest
flow, which is easiest to exercise through `bin/release.php` rather than by
hand.)

## 7. Rotation

**Rotating a client's upload token** — run `wrangler secret put` again with a
freshly generated value, then update the one GitHub secret
(`{NAMESPACE}_UPDATE_UPLOAD_TOKEN`) in that client's repo. Nothing else
changes.

**Rotating a signing key** is a different, manual process — it isn't a
Cloudflare operation at all, since the signing key never touches this
service. See [`docs/onboarding.md`](onboarding.md) §11 and
[`docs/spec.md`](spec.md) §4: generate a new keypair, commit the new public
half, deliver that build to affected sites by hand (SFTP break-glass), then
resume tagging.
