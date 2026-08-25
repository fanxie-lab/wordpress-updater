import { env, SELF } from "cloudflare:test";
import { beforeEach, describe, expect, it } from "vitest";

const BASE = "https://wp-updates-upload.fanxie.cloud";
const ZIP_KEY = "demo/packages/plugin-fx-demo-1.2.3.zip";
const ZIP_BODY = new Uint8Array([0x50, 0x4b, 0x03, 0x04, 0x00, 0x00]); // "PK\x03\x04"…

function manifestEnvelope(overrides: Record<string, unknown> = {}): string {
	const payload = JSON.stringify({
		type: "plugin",
		slug: "fx-demo",
		version: "1.2.3",
		package: "https://wp-updates.fanxie.cloud/demo/packages/plugin-fx-demo-1.2.3.zip",
		...overrides,
	});
	return JSON.stringify({
		schema: 1,
		payload: btoa(payload),
		// The worker checks shape, not cryptography — the site holds the public key.
		signature: btoa(String.fromCharCode(...new Uint8Array(64))),
	});
}

function put(path: string, body: BodyInit, token = "test-token-demo"): Promise<Response> {
	return SELF.fetch(`${BASE}${path}`, {
		method: "PUT",
		headers: { Authorization: `Bearer ${token}` },
		body,
	});
}

beforeEach(async () => {
	const listed = await env.BUCKET.list();
	await Promise.all(listed.objects.map((o) => env.BUCKET.delete(o.key)));
});

describe("auth", () => {
	it("rejects a missing token", async () => {
		const res = await SELF.fetch(`${BASE}/${ZIP_KEY}`, { method: "PUT", body: ZIP_BODY });
		expect(res.status).toBe(401);
	});

	it("rejects a wrong token without leaking which part failed", async () => {
		expect((await put(`/${ZIP_KEY}`, ZIP_BODY, "wrong")).status).toBe(401);
	});

	it("rejects a namespace with no configured token", async () => {
		expect((await put("/ghost/packages/plugin-x-1.0.0.zip", ZIP_BODY)).status).toBe(403);
	});

	it("rejects a valid token used on another namespace", async () => {
		expect((await put("/stilotex/packages/plugin-x-1.0.0.zip", ZIP_BODY, "test-token-demo")).status).toBe(401);
	});
});

describe("methods and paths", () => {
	it("rejects non-PUT", async () => {
		expect((await SELF.fetch(`${BASE}/${ZIP_KEY}`)).status).toBe(405);
	});

	it("rejects unrecognized path shapes", async () => {
		expect((await put("/demo/plugin-fx-demo.zip", ZIP_BODY)).status).toBe(404);
		expect((await put("/demo/packages/extra/plugin-x-1.0.0.zip", ZIP_BODY)).status).toBe(404);
		expect((await put("/demo/notes.txt", "hi")).status).toBe(404);
	});
});

describe("packages", () => {
	it("stores a zip under the token's namespace with immutable caching", async () => {
		const res = await put(`/${ZIP_KEY}`, ZIP_BODY);
		expect(res.status).toBe(200);

		const stored = await env.BUCKET.get(ZIP_KEY);
		expect(stored).not.toBeNull();
		expect(stored?.httpMetadata?.contentType).toBe("application/zip");
		expect(stored?.httpMetadata?.cacheControl).toContain("immutable");
	});

	it("rejects a body that is not a zip", async () => {
		expect((await put(`/${ZIP_KEY}`, "not a zip")).status).toBe(422);
	});
});

describe("manifests", () => {
	it("rejects a manifest whose package zip is not uploaded yet", async () => {
		const res = await put("/demo/plugin-fx-demo.json", manifestEnvelope());
		expect(res.status).toBe(409);
	});

	it("accepts a well-formed manifest once the zip exists", async () => {
		await put(`/${ZIP_KEY}`, ZIP_BODY);
		const res = await put("/demo/plugin-fx-demo.json", manifestEnvelope());
		expect(res.status).toBe(200);

		const stored = await env.BUCKET.get("demo/plugin-fx-demo.json");
		expect(stored?.httpMetadata?.contentType).toBe("application/json");
	});

	it("rejects an envelope that is not schema 1", async () => {
		await put(`/${ZIP_KEY}`, ZIP_BODY);
		const bad = JSON.stringify({ schema: 2, payload: btoa("{}"), signature: btoa("x") });
		expect((await put("/demo/plugin-fx-demo.json", bad)).status).toBe(422);
	});

	it("rejects a payload whose type/slug disagree with the filename", async () => {
		await put(`/${ZIP_KEY}`, ZIP_BODY);
		expect((await put("/demo/plugin-fx-demo.json", manifestEnvelope({ slug: "other" }))).status).toBe(422);
		expect((await put("/demo/plugin-fx-demo.json", manifestEnvelope({ type: "theme" }))).status).toBe(422);
	});

	it("rejects a package URL outside the namespace or off the public host", async () => {
		await put(`/${ZIP_KEY}`, ZIP_BODY);
		expect(
			(await put("/demo/plugin-fx-demo.json", manifestEnvelope({ package: "https://wp-updates.fanxie.cloud/stilotex/packages/plugin-fx-demo-1.2.3.zip" }))).status
		).toBe(422);
		expect(
			(await put("/demo/plugin-fx-demo.json", manifestEnvelope({ package: "https://evil.example/demo/packages/plugin-fx-demo-1.2.3.zip" }))).status
		).toBe(422);
	});

	it("rejects a non-semver version", async () => {
		await put(`/${ZIP_KEY}`, ZIP_BODY);
		expect((await put("/demo/plugin-fx-demo.json", manifestEnvelope({ version: "1.2" }))).status).toBe(422);
	});
});
