/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Standalone Vitest config (kept separate from vite.config.js so unit tests
 * run in a clean Node environment without the app's browser build plugins /
 * node polyfills).
 */
import { defineConfig } from 'vitest/config'

export default defineConfig({
	test: {
		environment: 'node',
		include: ['src/**/*.spec.js'],
		globals: false,
	},
})
