/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Global Vitest setup: mock the @nextcloud/* singletons so components and
 * api modules can be unit-tested in jsdom without a running Nextcloud.
 */
import { vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path) => '/ocs/v2.php/' + path,
	generateUrl: (path) => '/' + path,
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'en',
	getCanonicalLocale: () => 'en',
}))

// Nextcloud exposes t()/n() as globals in the app runtime.
globalThis.t = (app, text) => text
globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)
