// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright config for Dataforms end-to-end smoke tests.
 *
 * The tests run against a live Nextcloud instance with the app installed.
 * Configure via env vars (defaults target the local dev instance):
 *   DATAFORMS_BASE_URL  e.g. http://localhost:8080
 *   DATAFORMS_USER      e.g. admin
 *   DATAFORMS_PASSWORD  e.g. admin
 *
 * Run:  npm run test:e2e:install   (one-time, downloads the browser)
 *       npm run test:e2e
 */
export default defineConfig({
	testDir: './tests/e2e',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL: process.env.DATAFORMS_BASE_URL || 'http://localhost:8080',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		ignoreHTTPSErrors: true,
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
	],
})
