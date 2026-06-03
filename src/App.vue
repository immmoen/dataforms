<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="dataforms">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew
					:text="t('dataforms', 'New register')"
					@click="openCreate">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
				</NcAppNavigationNew>

				<NcAppNavigationItem
					v-for="register in registers"
					:key="register.id"
					:name="register.title"
					:active="register.id === selectedId"
					@click="select(register.id)">
					<template #icon>
						<FolderTableIcon :size="20" />
					</template>
					<template #actions>
						<NcActionButton @click="confirmDelete(register)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('dataforms', 'Delete') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcLoadingIcon v-if="loading" class="centered" :size="44" />

			<NcEmptyContent
				v-else-if="registers.length === 0"
				:name="t('dataforms', 'No registers yet')"
				:description="t('dataforms', 'Create your first register to start collecting structured records.')">
				<template #icon>
					<FolderTableIcon :size="20" />
				</template>
				<template #action>
					<NcButton type="primary" @click="openCreate">
						{{ t('dataforms', 'New register') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-else-if="!selected"
				:name="t('dataforms', 'Select a register')"
				:description="t('dataforms', 'Pick a register from the list, or create a new one.')">
				<template #icon>
					<FolderTableIcon :size="20" />
				</template>
			</NcEmptyContent>

			<div v-else class="register-detail">
				<h2>{{ selected.title }}</h2>
				<p class="description">
					{{ selected.description || t('dataforms', 'No description.') }}
				</p>
				<p class="meta">
					{{ t('dataforms', 'Owner: {owner}', { owner: selected.owner }) }}
				</p>
				<NcEmptyContent
					:name="t('dataforms', 'Fields, forms and records are coming next')"
					:description="t('dataforms', 'This register has no schema yet. The field editor and data-entry forms land in the next milestone.')">
					<template #icon>
						<TableIcon :size="20" />
					</template>
				</NcEmptyContent>
			</div>
		</NcAppContent>

		<!-- Create dialog -->
		<NcDialog
			v-if="showCreate"
			:name="t('dataforms', 'New register')"
			:can-close="!saving"
			@closing="showCreate = false">
			<div class="create-form">
				<NcTextField
					ref="titleField"
					v-model="draft.title"
					:label="t('dataforms', 'Title')"
					:required="true"
					@keydown.enter="submitCreate" />
				<NcTextArea
					v-model="draft.description"
					:label="t('dataforms', 'Description')"
					:placeholder="t('dataforms', 'What does this register track?')" />
			</div>
			<template #actions>
				<NcButton :disabled="saving" @click="showCreate = false">
					{{ t('dataforms', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || draft.title.trim() === ''"
					@click="submitCreate">
					{{ t('dataforms', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>
	</NcContent>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import FolderTableIcon from 'vue-material-design-icons/FolderTable.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import { listRegisters, createRegister, deleteRegister } from './api/registers.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcActionButton,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextArea,
		NcTextField,
		FolderTableIcon,
		TableIcon,
		PlusIcon,
		DeleteIcon,
	},
	data() {
		return {
			registers: [],
			loading: true,
			selectedId: null,
			showCreate: false,
			saving: false,
			draft: { title: '', description: '' },
		}
	},
	computed: {
		selected() {
			return this.registers.find((r) => r.id === this.selectedId) ?? null
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		async load() {
			this.loading = true
			try {
				this.registers = await listRegisters()
			} catch (e) {
				showError(t('dataforms', 'Could not load registers'))
				console.error(e)
			} finally {
				this.loading = false
			}
		},
		select(id) {
			this.selectedId = id
		},
		openCreate() {
			this.draft = { title: '', description: '' }
			this.showCreate = true
		},
		async submitCreate() {
			const title = this.draft.title.trim()
			if (title === '' || this.saving) {
				return
			}
			this.saving = true
			try {
				const register = await createRegister({
					title,
					description: this.draft.description.trim(),
				})
				this.registers.push(register)
				this.registers.sort((a, b) => a.title.localeCompare(b.title))
				this.selectedId = register.id
				this.showCreate = false
			} catch (e) {
				showError(t('dataforms', 'Could not create the register'))
				console.error(e)
			} finally {
				this.saving = false
			}
		},
		async confirmDelete(register) {
			if (!window.confirm(t('dataforms', 'Delete register "{title}"? Its records will be removed.', { title: register.title }))) {
				return
			}
			try {
				await deleteRegister(register.id)
				this.registers = this.registers.filter((r) => r.id !== register.id)
				if (this.selectedId === register.id) {
					this.selectedId = null
				}
			} catch (e) {
				showError(t('dataforms', 'Could not delete the register'))
				console.error(e)
			}
		},
	},
}
</script>

<style scoped>
.centered {
	margin: 30vh auto 0;
}

.register-detail {
	max-width: 720px;
	margin: 0 auto;
	padding: 24px;
}

.register-detail h2 {
	margin-bottom: 4px;
}

.register-detail .description {
	color: var(--color-text-maxcontrast);
}

.register-detail .meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-bottom: 24px;
}

.create-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: min(420px, 80vw);
	padding: 8px 0;
}
</style>
