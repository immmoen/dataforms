/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Ambient declarations for the Nextcloud runtime globals the app relies on but
 * that ship no types: the l10n helpers `t`/`n` (exposed both as globals and as
 * component template properties) and the `OC`/`OCA` namespaces. Lets vue-tsc
 * type-check the sources without a TypeScript migration.
 */

// Reuse the exact @nextcloud/l10n signatures so assigning them to the globals /
// component properties (main.js) type-checks too.
type Translate = typeof import('@nextcloud/l10n')['translate']
type TranslatePlural = typeof import('@nextcloud/l10n')['translatePlural']

// Make t()/n() resolvable inside component <template> blocks (they resolve
// against the component instance, not the module scope).
declare module 'vue' {
	interface ComponentCustomProperties {
		t: Translate
		n: TranslatePlural
	}
}

declare global {
	const t: Translate
	const n: TranslatePlural
	/* eslint-disable @typescript-eslint/no-explicit-any, no-var */
	var OC: any
	var OCA: any
	/* eslint-enable @typescript-eslint/no-explicit-any, no-var */
}

export {}
