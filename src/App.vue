<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="dataforms">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :name="t('dataforms', 'Registers')" :allow-collapse="false">
					<template #icon>
						<FolderTableIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<NcAppNavigationItem :name="t('dataforms', 'New register')" :disabled="true">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcEmptyContent
				:name="t('dataforms', 'Welcome to Dataforms')"
				:description="t('dataforms', 'Build registers, smart forms and views — no code required. Register and form management arrives in the next milestone.')">
				<template #icon>
					<FolderTableIcon :size="20" />
				</template>
			</NcEmptyContent>
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import FolderTableIcon from 'vue-material-design-icons/FolderTable.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcEmptyContent,
		FolderTableIcon,
		PlusIcon,
	},
}
</script>
