/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Build configuration. Uses the shared Nextcloud Vite preset so the output
 * lands in js/ with the naming the PageController expects (dataforms-main.js
 * / dataforms-main.css).
 *
 * extractLicenseInformation is disabled here because the REUSE license walk
 * over the full dependency tree is extremely slow on some platforms (minutes).
 * Re-enable it for REUSE-compliant release tarballs:
 *   createAppConfig({ main: 'src/main.js' }, { extractLicenseInformation: true })
 */
import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig(
	{
		main: 'src/main.js',
	},
	{
		extractLicenseInformation: false,
	},
)
