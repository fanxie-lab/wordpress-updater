/**
 * The write half of wp-updates: authenticated, namespace-scoped PUTs into R2.
 *
 * Reads never come here — the bucket's own custom domain serves them. This
 * worker exists because standing R2 API tokens scope to buckets, not prefixes
 * (spec §10.4): one client's CI must not be able to touch another client's
 * objects. Auth is a per-namespace bearer token held as a Worker secret; a
 * token authorizes writes under exactly one namespace prefix.
 *
 * The worker checks shape, not signatures: the Ed25519 public key lives on the
 * sites, and a manifest that fails shape checks here would have been refused
 * there anyway. What the worker uniquely enforces is tenancy (the token ↔
 * prefix binding) and ordering (no manifest before its package exists).
 */

export interface Env {
	BUCKET: R2Bucket;
	PUBLIC_HOST: string;
	[secret: string]: unknown;
}

const NAMESPACE = /^[a-z0-9][a-z0-9-]{0,31}$/;
const MANIFEST_FILE = /^(theme|plugin)-([a-z0-9][a-z0-9-]{1,62})\.json$/;
const PACKAGE_FILE = /^(theme|plugin)-([a-z0-9][a-z0-9-]{1,62})-(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?)\.zip$/;
const SEMVER = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/;
const BASE64 = /^[A-Za-z0-9+/]+={0,2}$/;
const MAX_MANIFEST_BYTES = 64 * 1024;
const MAX_PACKAGE_BYTES = 100 * 1024 * 1024;
const ED25519_SIGNATURE_BYTES = 64;

function json(status: number, body: Record<string, unknown>): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { "content-type": "application/json" },
	});
}

const refuse = (status: number, error: string): Response => json(status, { ok: false, error });

/** Constant-time-ish comparison: hash both sides so lengths never short-circuit. */
async function tokenMatches(presented: string, expected: string): Promise<boolean> {
	const encoder = new TextEncoder();
	const [a, b] = await Promise.all([
		crypto.subtle.digest("SHA-256", encoder.encode(presented)),
		crypto.subtle.digest("SHA-256", encoder.encode(expected)),
	]);
	return crypto.subtle.timingSafeEqual(a, b);
}

function decodeBase64(value: string): Uint8Array | null {
	if (!BASE64.test(value)) return null;
	try {
		return Uint8Array.from(atob(value), (c) => c.charCodeAt(0));
	} catch {
		return null;
	}
}

async function handleManifest(env: Env, ns: string, file: string, body: ArrayBuffer): Promise<Response> {
	const match = MANIFEST_FILE.exec(file);
	if (!match) return refuse(404, "not a manifest path");
	const [, type, slug] = match;

	if (body.byteLength > MAX_MANIFEST_BYTES) return refuse(413, "manifest too large");

	let envelope: unknown;
	try {
		envelope = JSON.parse(new TextDecoder("utf-8", { fatal: true, ignoreBOM: false }).decode(body));
	} catch {
		return refuse(422, "envelope is not JSON");
	}
	if (typeof envelope !== "object" || envelope === null) return refuse(422, "envelope is not an object");
	const { schema, payload, signature } = envelope as Record<string, unknown>;
	if (schema !== 1) return refuse(422, "unsupported envelope schema");
	if (typeof payload !== "string" || typeof signature !== "string") return refuse(422, "envelope is missing payload or signature");

	const signatureBytes = decodeBase64(signature);
	if (!signatureBytes || signatureBytes.length !== ED25519_SIGNATURE_BYTES) return refuse(422, "signature is not a 64-byte base64 value");

	const payloadBytes = decodeBase64(payload);
	if (!payloadBytes) return refuse(422, "payload is not base64");

	let manifest: Record<string, unknown>;
	try {
		manifest = JSON.parse(new TextDecoder("utf-8", { fatal: true, ignoreBOM: false }).decode(payloadBytes));
	} catch {
		return refuse(422, "payload is not JSON");
	}

	if (manifest.type !== type) return refuse(422, `payload type ${String(manifest.type)} does not match path type ${type}`);
	if (manifest.slug !== slug) return refuse(422, `payload slug ${String(manifest.slug)} does not match path slug ${slug}`);
	if (typeof manifest.version !== "string" || !SEMVER.test(manifest.version)) return refuse(422, "payload version is not semver");

	const expectedPackage = `https://${env.PUBLIC_HOST}/${ns}/packages/${type}-${slug}-${manifest.version}.zip`;
	if (manifest.package !== expectedPackage) return refuse(422, `payload package must be ${expectedPackage}`);

	// Ordering (spec §6.3): a manifest naming a download that does not exist
	// yet is an update offer that fails on every site that takes it.
	const packageKey = `${ns}/packages/${type}-${slug}-${manifest.version}.zip`;
	if ((await env.BUCKET.head(packageKey)) === null) return refuse(409, `upload ${packageKey} before its manifest`);

	const key = `${ns}/${type}-${slug}.json`;
	await env.BUCKET.put(key, body, {
		httpMetadata: { contentType: "application/json", cacheControl: "public, max-age=300" },
	});
	return json(200, { ok: true, key });
}

async function handlePackage(env: Env, ns: string, file: string, body: ArrayBuffer): Promise<Response> {
	if (!PACKAGE_FILE.test(file)) return refuse(404, "not a package path");
	if (body.byteLength > MAX_PACKAGE_BYTES) return refuse(413, "package too large");

	const magic = new Uint8Array(body.slice(0, 4));
	if (!(magic[0] === 0x50 && magic[1] === 0x4b && magic[2] === 0x03 && magic[3] === 0x04)) {
		return refuse(422, "body is not a zip archive");
	}

	const key = `${ns}/packages/${file}`;
	await env.BUCKET.put(key, body, {
		// Versioned filenames never change content; let every cache keep them.
		httpMetadata: { contentType: "application/zip", cacheControl: "public, max-age=31536000, immutable" },
	});
	return json(200, { ok: true, key });
}

export default {
	async fetch(request: Request, env: Env): Promise<Response> {
		if (request.method !== "PUT") return refuse(405, "only PUT is served here");

		const segments = new URL(request.url).pathname.replace(/^\/+/, "").split("/");
		const ns = segments[0] ?? "";
		if (!NAMESPACE.test(ns)) return refuse(404, "unrecognized path");

		const secretName = `UPLOAD_TOKEN_${ns.toUpperCase().replaceAll("-", "_")}`;
		const expected = env[secretName];
		if (typeof expected !== "string" || expected === "") return refuse(403, "unknown namespace");

		const auth = request.headers.get("authorization") ?? "";
		const presented = auth.startsWith("Bearer ") ? auth.slice(7) : "";
		if (presented === "" || !(await tokenMatches(presented, expected))) return refuse(401, "bad token");

		const body = await request.arrayBuffer();

		if (segments.length === 2) return handleManifest(env, ns, segments[1], body);
		if (segments.length === 3 && segments[1] === "packages") return handlePackage(env, ns, segments[2], body);
		return refuse(404, "unrecognized path");
	},
} satisfies ExportedHandler<Env>;
