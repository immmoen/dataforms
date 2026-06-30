/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Global Vitest setup: mock the @nextcloud/* singletons so components and
 * api modules can be unit-tested in jsdom without a running Nextcloud.
 */
import { vi } from 'vitest'
import { config } from '@vue/test-utils'

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

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	showInfo: vi.fn(),
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'en',
	getCanonicalLocale: () => 'en',
}))

// @nextcloud/vue dist components import their own .css, which Node's ESM loader
// rejects under vitest. Replace each imported NcX component with a slot-rendering
// stub so our own components mount in jsdom (see tests/nc-stub.js). vi.mock needs
// a literal module id, so the paths are spelled out.
const ncStub = vi.hoisted(() => async () => ({ default: (await import('./nc-stub.js')).default }))
vi.mock('@nextcloud/vue/components/NcActionButton', ncStub)
vi.mock('@nextcloud/vue/components/NcActionCaption', ncStub)
vi.mock('@nextcloud/vue/components/NcActionCheckbox', ncStub)
vi.mock('@nextcloud/vue/components/NcActions', ncStub)
vi.mock('@nextcloud/vue/components/NcActionSeparator', ncStub)
vi.mock('@nextcloud/vue/components/NcAppContent', ncStub)
vi.mock('@nextcloud/vue/components/NcAppNavigation', ncStub)
vi.mock('@nextcloud/vue/components/NcAppNavigationCaption', ncStub)
vi.mock('@nextcloud/vue/components/NcAppNavigationItem', ncStub)
vi.mock('@nextcloud/vue/components/NcAppNavigationNew', ncStub)
vi.mock('@nextcloud/vue/components/NcButton', ncStub)
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', ncStub)
vi.mock('@nextcloud/vue/components/NcContent', ncStub)
vi.mock('@nextcloud/vue/components/NcDialog', ncStub)
vi.mock('@nextcloud/vue/components/NcEmptyContent', ncStub)
vi.mock('@nextcloud/vue/components/NcLoadingIcon', ncStub)
vi.mock('@nextcloud/vue/components/NcRichText', ncStub)
vi.mock('@nextcloud/vue/components/NcSelect', ncStub)
vi.mock('@nextcloud/vue/components/NcTextArea', ncStub)
vi.mock('@nextcloud/vue/components/NcTextField', ncStub)

// Nextcloud exposes t()/n() as globals in the app runtime.
globalThis.t = (app, text) => text
globalThis.n = (app, singular, plural, count) => (count === 1 ? singular : plural)

// …and as instance properties inside component templates (matching main.js,
// which registers them on app.config.globalProperties).
config.global.mocks = { t: globalThis.t, n: globalThis.n }
