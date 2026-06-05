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

				<NcAppNavigationCaption v-if="favoriteRegisters.length" :name="t('dataforms', 'Favourites')" />
				<NcAppNavigationItem
					v-for="register in favoriteRegisters"
					:key="'fav-' + register.id"
					:name="register.title"
					:active="register.id === selectedId"
					:counter-number="register.recordCount"
					@click="select(register.id)">
					<template #icon>
						<span class="reg-dot" :style="{ backgroundColor: register.color || 'var(--color-primary-element)' }" />
					</template>
					<template #actions>
						<NcActionButton @click="toggleFavorite(register)">
							<template #icon><StarIcon :size="20" /></template>
							{{ t('dataforms', 'Remove from favourites') }}
						</NcActionButton>
						<NcActionButton v-if="register.canManage" @click="confirmDelete(register)">
							<template #icon><DeleteIcon :size="20" /></template>
							{{ t('dataforms', 'Delete') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationCaption v-if="favoriteRegisters.length" :name="t('dataforms', 'All registers')" />
				<NcAppNavigationItem
					v-for="register in otherRegisters"
					:key="register.id"
					:name="register.title"
					:active="register.id === selectedId"
					:counter-number="register.recordCount"
					@click="select(register.id)">
					<template #icon>
						<span class="reg-dot" :style="{ backgroundColor: register.color || 'var(--color-primary-element)' }" />
					</template>
					<template #actions>
						<NcActionButton @click="toggleFavorite(register)">
							<template #icon><StarOutlineIcon :size="20" /></template>
							{{ t('dataforms', 'Add to favourites') }}
						</NcActionButton>
						<NcActionButton v-if="register.canManage" @click="confirmDelete(register)">
							<template #icon><DeleteIcon :size="20" /></template>
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

			<div v-else-if="!selected" class="dashboard">
				<div class="dash-head">
					<h2>{{ t('dataforms', 'Registers') }}</h2>
					<p class="dash-sub">{{ t('dataforms', 'Pick a register to view its records, or create a new one.') }}</p>
				</div>
				<div class="reg-grid">
					<button
						v-for="register in registers"
						:key="register.id"
						class="reg-card"
						@click="select(register.id)">
						<span class="reg-card-top">
							<span class="reg-card-icon" :style="{ backgroundColor: register.color || 'var(--color-primary-element)' }">
								<FolderTableIcon :size="20" />
							</span>
							<StarIcon v-if="register.favorite" :size="16" class="reg-card-star" />
						</span>
						<span class="reg-card-title">{{ register.title }}</span>
						<span v-if="register.description" class="reg-card-desc">{{ register.description }}</span>
						<span class="reg-card-meta">
							{{ n('dataforms', '%n record', '%n records', register.recordCount || 0) }}
						</span>
					</button>
					<button class="reg-card reg-card-new" @click="openCreate">
						<PlusIcon :size="28" />
						<span>{{ t('dataforms', 'New register') }}</span>
					</button>
				</div>
			</div>

			<div v-else class="register-detail" :class="{ 'is-records': activeTab === 'records' }">
				<div class="register-head">
					<div class="head-row">
						<h2>{{ selected.title }}</h2>
						<div class="head-actions">
							<NcButton type="tertiary" :aria-label="t('dataforms', 'Copy link to this register')" @click="copyLink">
								<template #icon>
									<LinkIcon :size="20" />
								</template>
								{{ t('dataforms', 'Copy link') }}
							</NcButton>
							<NcButton v-if="selected.canManage" type="secondary" @click="showShare = true">
								<template #icon>
									<ShareVariantIcon :size="20" />
								</template>
								{{ t('dataforms', 'Share') }}
							</NcButton>
						</div>
					</div>
					<p v-if="selected.description" class="description">
						{{ selected.description }}
					</p>
				</div>

				<div v-if="tabs.length > 1" class="tabs">
					<button
						v-for="tab in tabs"
						:key="tab.id"
						class="tab"
						:class="{ active: activeTab === tab.id }"
						@click="activeTab = tab.id">
						{{ tab.label }}
					</button>
				</div>

				<RecordsView
					v-if="activeTab === 'records'"
					:key="'rec-' + selected.id"
					:register-id="selected.id"
					:can-write="selected.canWrite"
					:can-manage="selected.canManage" />
				<SchemaEditor
					v-else-if="activeTab === 'fields'"
					:key="'fld-' + selected.id"
					:register-id="selected.id"
					:can-manage="selected.canManage" />
				<FormBuilder
					v-else-if="activeTab === 'forms'"
					:key="'frm-' + selected.id"
					:register-id="selected.id"
					:can-manage="selected.canManage" />
				<RuleBuilder
					v-else
					:key="'rul-' + selected.id"
					:register-id="selected.id"
					:can-manage="selected.canManage" />

				<ShareDialog v-if="showShare" :register="selected" @close="showShare = false" />
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
				<div class="color-field">
					<label class="color-label">{{ t('dataforms', 'Colour') }}</label>
					<div class="swatches">
						<button
							v-for="c in colors"
							:key="c"
							class="swatch"
							:class="{ selected: draft.color === c }"
							:style="{ backgroundColor: c }"
							:aria-label="c"
							@click="draft.color = c" />
					</div>
				</div>
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
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import FolderTableIcon from 'vue-material-design-icons/FolderTable.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import LinkIcon from 'vue-material-design-icons/LinkVariant.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'

import SchemaEditor from './components/SchemaEditor.vue'
import RecordsView from './components/RecordsView.vue'
import RuleBuilder from './components/RuleBuilder.vue'
import FormBuilder from './components/FormBuilder.vue'
import ShareDialog from './components/ShareDialog.vue'
import { listRegisters, createRegister, deleteRegister, favoriteRegister, REGISTER_COLORS } from './api/registers.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationCaption,
		NcAppNavigationNew,
		NcActionButton,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextArea,
		NcTextField,
		SchemaEditor,
		RecordsView,
		RuleBuilder,
		FormBuilder,
		ShareDialog,
		FolderTableIcon,
		PlusIcon,
		DeleteIcon,
		ShareVariantIcon,
		LinkIcon,
		StarIcon,
		StarOutlineIcon,
	},
	data() {
		return {
			registers: [],
			loading: true,
			selectedId: null,
			activeTab: 'records',
			showShare: false,
			showCreate: false,
			saving: false,
			draft: { title: '', description: '', color: REGISTER_COLORS[0] },
			colors: REGISTER_COLORS,
		}
	},
	computed: {
		selected() {
			return this.registers.find((r) => r.id === this.selectedId) ?? null
		},
		favoriteRegisters() {
			return this.registers.filter((r) => r.favorite)
		},
		otherRegisters() {
			return this.favoriteRegisters.length ? this.registers.filter((r) => !r.favorite) : this.registers
		},
		tabs() {
			const tabs = [{ id: 'records', label: t('dataforms', 'Records') }]
			// The schema/forms/rules builders are manager-only; data-entry users
			// see just Records (they enter data through the form, not the schema).
			if (this.selected?.canManage) {
				tabs.push(
					{ id: 'fields', label: t('dataforms', 'Fields') },
					{ id: 'forms', label: t('dataforms', 'Forms') },
					{ id: 'rules', label: t('dataforms', 'Rules') },
				)
			}
			return tabs
		},
	},
	async mounted() {
		await this.load()
		this.applyHash()
		window.addEventListener('hashchange', this.applyHash)
	},
	beforeUnmount() {
		window.removeEventListener('hashchange', this.applyHash)
	},
	watch: {
		activeTab() {
			this.syncHash()
		},
	},
	methods: {
		n,
		async toggleFavorite(register) {
			const next = !register.favorite
			try {
				const updated = await favoriteRegister(register.id, next)
				const i = this.registers.findIndex((r) => r.id === register.id)
				if (i !== -1) this.registers.splice(i, 1, updated)
			} catch (e) {
				showError(t('dataforms', 'Could not update favourites'))
				console.error(e)
			}
		},
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
			this.activeTab = 'records'
			this.showShare = false
			this.syncHash()
		},
		// Deep-linking: the URL hash reflects the open register + tab, so a
		// register can be bookmarked and shared.
		syncHash() {
			const next = this.selectedId ? `#/register/${this.selectedId}/${this.activeTab}` : '#/'
			if (window.location.hash !== next) {
				window.history.replaceState(null, '', next)
			}
		},
		applyHash() {
			const m = window.location.hash.match(/^#\/register\/(\d+)(?:\/(\w+))?/)
			if (!m) {
				return
			}
			const id = Number(m[1])
			if (this.registers.some((r) => r.id === id)) {
				this.selectedId = id
				this.activeTab = this.tabs.some((tb) => tb.id === m[2]) ? m[2] : 'records'
			}
		},
		copyLink() {
			const url = window.location.origin + window.location.pathname + `#/register/${this.selectedId}/${this.activeTab}`
			navigator.clipboard?.writeText(url).then(
				() => showSuccess(t('dataforms', 'Link copied to clipboard')),
				() => showError(t('dataforms', 'Could not copy the link')),
			)
		},
		openCreate() {
			this.draft = { title: '', description: '', color: REGISTER_COLORS[0] }
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
					color: this.draft.color,
					icon: 'table',
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

.reg-dot {
	display: inline-block;
	width: 14px;
	height: 14px;
	border-radius: 4px;
}

/* Dashboard landing */
.dashboard {
	max-width: 1100px;
	margin: 0 auto;
	padding: 28px 28px 60px;
}

.dash-head h2 {
	margin: 0 0 2px;
	font-size: 1.6em;
}

.dash-sub {
	color: var(--color-text-maxcontrast);
	margin: 0 0 20px;
}

.reg-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 16px;
}

.reg-card {
	display: flex;
	flex-direction: column;
	gap: 6px;
	text-align: left;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 16px;
	cursor: pointer;
	transition: box-shadow 0.12s ease, border-color 0.12s ease, transform 0.12s ease;
}

.reg-card:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
	transform: translateY(-1px);
}

.reg-card-top {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.reg-card-icon {
	width: 40px;
	height: 40px;
	border-radius: 10px;
	display: grid;
	place-items: center;
	color: #fff;
}

.reg-card-star {
	color: var(--color-warning, #e0b400);
}

.reg-card-title {
	font-weight: 600;
	font-size: 1.05em;
	margin-top: 6px;
}

.reg-card-desc {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	flex: 1;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.reg-card-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.82em;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border-dark, var(--color-border));
}

.reg-card-new {
	align-items: center;
	justify-content: center;
	border-style: dashed;
	color: var(--color-text-maxcontrast);
	min-height: 140px;
}

.register-detail {
	max-width: 920px;
	margin: 0 auto;
	transition: max-width 0.15s ease;
}

/* The records tab holds wide tables, so let it use (almost) the full width.
   Other tabs (fields/forms/rules) stay narrow and readable. */
.register-detail.is-records {
	max-width: min(1760px, 100%);
}

.register-head {
	padding: 24px 24px 0;
}

.head-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}

.head-actions {
	display: flex;
	gap: 8px;
	flex: none;
}

.register-detail h2 {
	margin-bottom: 4px;
}

.tabs {
	display: flex;
	gap: 2px;
	border-bottom: 1px solid var(--color-border);
	padding: 0 24px;
	margin-top: 12px;
}

.tab {
	background: none;
	border: none;
	border-bottom: 2px solid transparent;
	padding: 10px 16px;
	margin-bottom: -1px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.tab:hover {
	color: var(--color-main-text);
}

.tab.active {
	color: var(--color-primary-element);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
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

.color-label {
	display: block;
	font-weight: 600;
	font-size: 0.88em;
	margin-bottom: 6px;
}

.swatches {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.swatch {
	width: 28px;
	height: 28px;
	border-radius: 8px;
	border: 2px solid transparent;
	cursor: pointer;
	padding: 0;
}

.swatch.selected {
	border-color: var(--color-main-text);
	box-shadow: 0 0 0 2px var(--color-main-background) inset;
}
</style>
