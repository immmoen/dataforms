/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Entry point for the Dataforms SPA. Mounts the root component into the
 * shell rendered by templates/main.php.
 */
import { createApp } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import App from './App.vue'
import './main.css'

const app = createApp(App)

// Make the Nextcloud translation helpers available as global properties,
// matching the convention used across Nextcloud apps.
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.mount('#dataforms')
