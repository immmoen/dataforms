/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Test stub for @nextcloud/vue components. The real dist modules import their
 * own stylesheets, which Node's ESM loader can't handle under vitest; aliasing
 * every NcX import to this stub lets our own components mount in jsdom. The
 * stub renders all slots so slotted content (dialog bodies, named #action /
 * #icon slots) still renders — enough to exercise our templates.
 */
import { h } from 'vue'

export default {
	name: 'NcStub',
	inheritAttrs: false,
	setup(_, { slots }) {
		return () => h('div', Object.values(slots).flatMap((slot) => {
			try {
				return slot()
			} catch {
				return []
			}
		}))
	},
}
