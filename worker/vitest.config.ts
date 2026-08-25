import { defineConfig } from "vitest/config";
import { cloudflareTest } from "@cloudflare/vitest-pool-workers";

export default defineConfig({
	plugins: [
		cloudflareTest({
			wrangler: { configPath: "./wrangler.jsonc" },
			miniflare: {
				bindings: {
					UPLOAD_TOKEN_DEMO: "test-token-demo",
					UPLOAD_TOKEN_STILOTEX: "test-token-stilotex",
				},
			},
		}),
	],
});
