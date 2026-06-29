/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Vitest config for unit tests. Runs in jsdom so Vue components can be mounted
 * and asserted against the DOM; the @nextcloud/* singletons are mocked in
 * tests/setup.js. Kept separate from vite.config.js so unit tests don't pull in
 * the app's production build plugins.
 */
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'jsdom',
		include: ['src/**/*.spec.js'],
		globals: true,
		setupFiles: ['tests/setup.js'],
		coverage: {
			provider: 'v8',
			all: true,
			include: ['src/**/*.{js,vue}'],
			exclude: ['src/**/*.spec.js', 'src/main.js', 'src/reference.js', 'src/admin.js'],
			reporter: ['text', 'json', 'lcov', 'html'],
			// No global thresholds yet: the PR gate enforces 100% diff-coverage and
			// the nightly stage carries the global ratchet (see issue #2).
		},
	},
})
