<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="records-view">
		<div class="toolbar">
			<NcTextField v-model="search"
				class="search"
				:label="t('dataforms', 'Search records')"
				label-visible
				type="search"
				@update:model-value="onSearch" />
			<NcSelect v-if="fields.length && views.length"
				:model-value="activeView"
				:options="viewOptions"
				label="title"
				:clearable="true"
				:placeholder="t('dataforms', 'All records')"
				class="view-select"
				@update:model-value="onSelectView" />
			<NcButton :variant="activeFilters.length ? 'secondary' : 'tertiary'" @click="toggleFilterBar">
				<template #icon>
					<FilterIcon :size="20" />
				</template>
				{{ activeFilters.length ? t('dataforms', 'Filter ({n})', { n: activeFilters.length }) : t('dataforms', 'Filter') }}
			</NcButton>
			<span class="spacer" />
			<input ref="importInput"
				type="file"
				accept=".csv,text/csv"
				class="hidden-file"
				@change="onImportFile">

			<!-- One tidy menu for everything secondary. -->
			<NcActions :menu-name="t('dataforms', 'More')" :force-name="false">
				<template #icon>
					<DotsIcon :size="20" />
				</template>
				<NcActionButton close-after-click @click="load()">
					<template #icon>
						<RefreshIcon :size="20" />
					</template>
					{{ t('dataforms', 'Refresh') }}
				</NcActionButton>
				<NcActionButton v-if="fields.length" close-after-click @click="openSaveView">
					<template #icon>
						<ContentSaveIcon :size="20" />
					</template>
					{{ t('dataforms', 'Save current view…') }}
				</NcActionButton>
				<NcActionButton v-if="activeView && activeView.isOwner" close-after-click @click="removeActiveView">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('dataforms', 'Delete this view') }}
				</NcActionButton>
				<NcActionSeparator />
				<NcActionButton v-if="canWrite"
					:disabled="fields.length === 0 || importing"
					close-after-click
					@click="showImport = true">
					<template #icon>
						<UploadIcon :size="20" />
					</template>
					{{ importing ? t('dataforms', 'Importing…') : t('dataforms', 'Import from CSV…') }}
				</NcActionButton>
				<NcActionButton :disabled="fields.length === 0 || records.length === 0" close-after-click @click="exportCsv">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
					{{ t('dataforms', 'Export to CSV') }}
				</NcActionButton>
				<template v-if="fields.length">
					<NcActionSeparator />
					<NcActionCaption :name="t('dataforms', 'Columns')" />
					<NcActionCheckbox v-for="field in fields"
						:key="field.id"
						:model-value="isColumnVisible(field)"
						@update:model-value="toggleColumn(field)">
						{{ field.label }}
					</NcActionCheckbox>
				</template>
			</NcActions>

			<NcActions v-if="canWrite && forms.length"
				type="primary"
				:menu-name="t('dataforms', 'New record')"
				:disabled="fields.length === 0">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				<NcActionButton @click="openNew(null)">
					{{ t('dataforms', 'Blank (all fields)') }}
				</NcActionButton>
				<NcActionButton v-for="f in forms" :key="f.id" @click="openNew(f)">
					{{ f.title }}
				</NcActionButton>
			</NcActions>
			<NcButton v-else-if="canWrite"
				variant="primary"
				:disabled="fields.length === 0"
				@click="openNew(null)">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dataforms', 'New record') }}
			</NcButton>
		</div>

		<RecordsFilterBar v-if="showFilter"
			:fields="fields"
			:initial-filters="activeFilters"
			@apply="onFilterApply"
			@clear="onFilterClear" />

		<NcLoadingIcon v-if="loading" class="centered" :size="32" />

		<NcEmptyContent v-else-if="fields.length === 0"
			:name="t('dataforms', 'Define fields first')"
			:description="t('dataforms', 'This register has no fields yet. Add some in the Fields tab, then you can enter records.')">
			<template #icon>
				<TableIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="records.length === 0"
			:name="t('dataforms', 'No records yet')"
			:description="t('dataforms', 'Add the first record with the New record button.')">
			<template #icon>
				<TableIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<RecordsTable :records="records"
				:columns="columns"
				:can-manage="canManage"
				:current-user-id="currentUserId"
				:sort="sort"
				:direction="direction"
				@sort="toggleSort"
				@detail="openDetail"
				@edit="openEdit"
				@delete="remove"
				@inline-saved="onInlineSaved"
				@editing-change="inlineEditing = $event"
				@reload="load" />

			<div class="pager">
				<span>{{ rangeLabel }}</span>
				<NcButton :disabled="page === 0" @click="prev">
					{{ t('dataforms', 'Previous') }}
				</NcButton>
				<NcButton :disabled="(page + 1) * limit >= total" @click="next">
					{{ t('dataforms', 'Next') }}
				</NcButton>
			</div>
		</template>

		<RecordForm v-if="showForm"
			:register-id="registerId"
			:fields="fields"
			:rules="rules"
			:record="editing ?? undefined"
			:form="activeForm ?? undefined"
			@saved="onSaved"
			@close="showForm = false" />

		<RecordDetail v-if="showDetail"
			:fields="fields"
			:record="detailRecord || {}"
			:can-edit="canModify(detailRecord)"
			@edit="onDetailEdit"
			@close="showDetail = false" />

		<NcDialog v-if="showSaveView"
			:name="t('dataforms', 'Save as view')"
			size="normal"
			@closing="showSaveView = false">
			<div class="save-view-form">
				<NcTextField v-model="newView.title" :label="t('dataforms', 'View name')" :required="true" />
				<p class="hint">
					{{ t('dataforms', 'Saves the current columns, filters, sort and search.') }}
				</p>
				<NcCheckboxRadioSwitch v-model="newView.shared">
					{{ t('dataforms', 'Share with everyone who can see this register') }}
				</NcCheckboxRadioSwitch>
			</div>
			<template #actions>
				<NcButton @click="showSaveView = false">
					{{ t('dataforms', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="newView.title.trim() === ''" @click="saveView">
					{{ t('dataforms', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-if="showImport"
			:name="t('dataforms', 'Import records from CSV')"
			size="normal"
			@closing="showImport = false">
			<div class="import-help">
				<p>{{ t('dataforms', 'Upload a CSV file. The first row must be column headers that match your field names; each following row becomes one record.') }}</p>
				<ul>
					<li>{{ t('dataforms', 'Column headers are matched to field labels (e.g. “Activity name”) or machine names (e.g. “activity_name”). Unmatched columns are ignored.') }}</li>
					<li>{{ t('dataforms', 'Yes/No fields accept yes/no or true/false. Multi-select cells use comma-separated values.') }}</li>
					<li>{{ t('dataforms', 'Relation and file fields cannot be imported and are skipped. Computed fields are recalculated.') }}</li>
					<li>{{ t('dataforms', 'Rows that fail validation (e.g. a missing required field) are reported and skipped.') }}</li>
				</ul>
				<p class="tip">
					{{ t('dataforms', 'Tip: export first to see the exact column layout, or download a header template.') }}
				</p>
				<div v-if="importResult" class="import-result">
					<p><strong>{{ t('dataforms', 'Imported {ok}, {failed} failed', { ok: importResult.imported, failed: importResult.failed }) }}</strong></p>
					<ul v-if="importResult.errors && importResult.errors.length">
						<li v-for="(err, i) in importResult.errors" :key="i" class="err-row">
							{{ err }}
						</li>
					</ul>
				</div>
			</div>
			<template #actions>
				<NcButton @click="downloadTemplate">
					{{ t('dataforms', 'Download template') }}
				</NcButton>
				<NcButton variant="primary" :disabled="importing" @click="triggerImport">
					{{ importing ? t('dataforms', 'Importing…') : t('dataforms', 'Choose CSV file') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import FilterIcon from 'vue-material-design-icons/Filter.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import DotsIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'

import RecordForm from './RecordForm.vue'
import RecordDetail from './RecordDetail.vue'
import RecordsFilterBar from './RecordsFilterBar.vue'
import RecordsTable from './RecordsTable.vue'
import { columnsFor, isColumnVisible, toggleColumnList, viewDefinition, stateFromView } from './records/viewState.js'
import { listRecords, deleteRecord, csvExportUrl, importCsv } from '../api/records.js'
import { listRules } from '../api/rules.js'
import { listViews, createView, deleteView } from '../api/views.js'
import { listForms } from '../api/forms.js'

export default {
	name: 'RecordsView',
	components: {
		NcActions,
		NcActionButton,
		NcActionCheckbox,
		NcActionCaption,
		NcActionSeparator,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		PlusIcon,
		FilterIcon,
		DeleteIcon,
		DownloadIcon,
		UploadIcon,
		TableIcon,
		ContentSaveIcon,
		DotsIcon,
		RefreshIcon,
		RecordForm,
		RecordDetail,
		RecordsFilterBar,
		RecordsTable,
	},
	props: {
		registerId: { type: Number, required: true },
		canWrite: { type: Boolean, default: false },
		canManage: { type: Boolean, default: false },
		openFormId: { type: Number, default: null },
	},
	emits: ['form-consumed'],
	data() {
		return {
			currentUserId: getCurrentUser()?.uid ?? '',
			/** @type {import('@/types/models').RecordRow[]} */
			records: [],
			/** @type {import('@/types/models').Field[]} */
			fields: [],
			/** @type {import('@/types/models').Rule[]} */
			rules: [],
			total: 0,
			loading: true,
			search: '',
			page: 0,
			limit: 25,
			showForm: false,
			/** @type {import('@/types/models').RecordRow|null} */
			editing: null,
			showDetail: false,
			/** @type {import('@/types/models').RecordRow|null} */
			detailRecord: null,
			importing: false,
			showImport: false,
			/** @type {{imported:number,failed:number,errors:string[]}|null} */
			importResult: null,
			/** @type {any} */
			searchTimer: null,
			sort: 'updated',
			direction: 'DESC',
			showFilter: false,
			/** @type {Array<{field:string,op:string,value?:any}>} */
			activeFilters: [],
			// True while an inline cell edit is open, so a tab/window-focus refresh
			// doesn't wipe the in-progress edit (the editor itself lives in RecordsTable).
			inlineEditing: false,
			/** @type {import('@/types/models').View[]} */
			views: [],
			/** @type {number|null} */
			activeViewId: null,
			/** @type {string[]} */
			visibleColumns: [],
			showSaveView: false,
			newView: { title: '', shared: false },
			/** @type {import('@/types/models').Form[]} */
			forms: [],
			/** @type {import('@/types/models').Form|null} */
			activeForm: null,
		}
	},
	computed: {
		columns() {
			return columnsFor(this.fields, this.visibleColumns)
		},
		viewOptions() {
			return this.views.map((v) => ({ id: v.id, title: v.title, isOwner: v.isOwner, definition: v.definition }))
		},
		activeView() {
			return this.viewOptions.find((v) => v.id === this.activeViewId) ?? null
		},
		rangeLabel() {
			if (this.total === 0) return ''
			const from = this.page * this.limit + 1
			const to = Math.min(this.total, (this.page + 1) * this.limit)
			return t('dataforms', '{from}–{to} of {total}', { from, to, total: this.total })
		},
	},
	watch: {
		registerId() {
			this.page = 0
			this.search = ''
			this.sort = 'updated'
			this.direction = 'DESC'
			this.showFilter = false
			this.activeFilters = []
			this.activeViewId = null
			this.visibleColumns = []
			this.reload()
		},
	},
	async mounted() {
		this.rules = await listRules(this.registerId).catch(() => [])
		this.views = await listViews(this.registerId).catch(() => [])
		this.forms = await listForms(this.registerId).catch(() => [])
		await this.load()
		// Keep the list fresh when the user comes back to the tab/window.
		document.addEventListener('visibilitychange', this.onVisible)
		window.addEventListener('focus', this.onWindowFocus)
		// A deep link (?form=) asks us to open that form's entry screen.
		if (this.openFormId) {
			const form = this.forms.find((f) => f.id === this.openFormId)
			if (form && this.canWrite) {
				this.openNew(form)
			}
			this.$emit('form-consumed')
		}
	},
	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.onVisible)
		window.removeEventListener('focus', this.onWindowFocus)
	},
	methods: {
		t,
		onVisible() {
			if (document.visibilityState === 'visible') {
				this.refreshIfIdle()
			}
		},
		onWindowFocus() {
			this.refreshIfIdle()
		},
		// Reload only when nothing is mid-interaction (no open dialog or inline edit).
		refreshIfIdle() {
			if (this.loading || this.showForm || this.showDetail || this.showImport || this.inlineEditing) {
				return
			}
			this.load()
		},
		async load() {
			this.loading = true
			try {
				const params = {
					limit: this.limit,
					offset: this.page * this.limit,
					search: this.search,
					sort: this.sort,
					direction: this.direction,
				}
				if (this.activeFilters.length) {
					params.filter = JSON.stringify(this.activeFilters)
				}
				const data = await listRecords(this.registerId, params)
				this.records = data.records
				this.fields = data.fields
				this.total = data.total
			} catch (e) {
				showError(t('dataforms', 'Could not load records'))
				console.error(e)
			} finally {
				this.loading = false
			}
		},
		toggleFilterBar() {
			this.showFilter = !this.showFilter
		},
		// ---- filter bar (RecordsFilterBar) ----
		onFilterApply(filters) {
			this.activeFilters = filters
			this.page = 0
			this.load()
		},
		onFilterClear() {
			this.activeFilters = []
			this.page = 0
			this.load()
		},
		// ---- saved views ----
		isColumnVisible(field) {
			return isColumnVisible(this.fields, this.visibleColumns, field)
		},
		toggleColumn(field) {
			this.visibleColumns = toggleColumnList(this.fields, this.visibleColumns, field)
		},
		onSelectView(view) {
			if (!view) {
				this.activeViewId = null
				return
			}
			this.activeViewId = view.id
			Object.assign(this, stateFromView(view))
			this.page = 0
			this.load()
		},
		openSaveView() {
			this.newView = { title: this.activeView?.title ?? '', shared: false }
			this.showSaveView = true
		},
		async saveView() {
			if (this.newView.title.trim() === '') {
				return
			}
			try {
				const view = await createView(this.registerId, {
					title: this.newView.title.trim(),
					shared: this.newView.shared,
					definition: viewDefinition({
						columns: this.columns.map((f) => f.machineName),
						filters: this.activeFilters,
						sort: this.sort,
						direction: this.direction,
						search: this.search,
					}),
				})
				this.views.push(view)
				this.activeViewId = view.id
				this.showSaveView = false
				showSuccess(t('dataforms', 'View saved'))
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not save the view'))
				console.error(e)
			}
		},
		async removeActiveView() {
			const view = this.activeView
			if (!view || !window.confirm(t('dataforms', 'Delete this view?'))) {
				return
			}
			const id = view.id
			try {
				await deleteView(id)
				this.views = this.views.filter((v) => v.id !== id)
				this.activeViewId = null
			} catch (e) {
				showError(t('dataforms', 'Could not delete the view'))
				console.error(e)
			}
		},
		toggleSort(field) {
			if (this.sort === field.machineName) {
				this.direction = this.direction === 'ASC' ? 'DESC' : 'ASC'
			} else {
				this.sort = field.machineName
				this.direction = 'ASC'
			}
			this.page = 0
			this.load()
		},
		async reload() {
			this.rules = await listRules(this.registerId).catch(() => [])
			this.forms = await listForms(this.registerId).catch(() => [])
			await this.load()
		},
		onSearch() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => {
				this.page = 0
				this.load()
			}, 300)
		},
		/** @param {import('@/types/models').Form|null} form */
		openNew(form = null) {
			this.editing = null
			this.activeForm = form
			this.showForm = true
		},
		canModify(record) {
			// A user may edit/delete a record they created, or any record if
			// they manage the register. Mirrors the server-side rule.
			return this.canManage || record.createdBy === this.currentUserId
		},
		openEdit(record) {
			this.showDetail = false
			this.editing = record
			this.activeForm = null // edit shows all fields so nothing is uneditable
			this.showForm = true
		},
		openDetail(record) {
			this.detailRecord = record
			this.showDetail = true
		},
		triggerImport() {
			(/** @type {HTMLInputElement} */ (this.$refs.importInput)).click()
		},
		// A cell saved in place (RecordsTable) — swap the updated record in.
		onInlineSaved(updated) {
			const i = this.records.findIndex((r) => r.id === updated.id)
			if (i !== -1) {
				this.records.splice(i, 1, updated)
			}
		},
		onDetailEdit(record) {
			this.openEdit(record)
		},
		onSaved() {
			this.showForm = false
			this.load()
		},
		async onImportFile(event) {
			const file = event.target.files?.[0]
			event.target.value = '' // allow re-selecting the same file
			if (!file) return
			this.importing = true
			this.importResult = null
			try {
				const csv = await file.text()
				const result = await importCsv(this.registerId, csv)
				this.importResult = result
				if (result.failed > 0) {
					showWarning(t('dataforms', 'Imported {ok}, {failed} row(s) failed', { ok: result.imported, failed: result.failed }))
				} else {
					showSuccess(t('dataforms', 'Imported {ok} record(s)', { ok: result.imported }))
				}
				this.load()
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not import the CSV'))
				console.error(e)
			} finally {
				this.importing = false
			}
		},
		downloadTemplate() {
			// Header-only CSV from the importable field labels.
			const headers = this.fields
				.filter((f) => !['relation', 'file'].includes(f.type))
				.map((f) => '"' + f.label.replace(/"/g, '""') + '"')
				.join(',')
			const blob = new Blob(['﻿' + headers + '\n'], { type: 'text/csv;charset=utf-8' })
			const a = document.createElement('a')
			a.href = URL.createObjectURL(blob)
			a.download = 'template.csv'
			a.click()
			URL.revokeObjectURL(a.href)
		},
		async remove(record) {
			if (!window.confirm(t('dataforms', 'Delete this record?'))) return
			try {
				await deleteRecord(record.id)
				this.load()
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not delete the record'))
				console.error(e)
			}
		},
		exportCsv() {
			window.location.href = csvExportUrl(this.registerId)
		},
		prev() {
			if (this.page > 0) { this.page--; this.load() }
		},
		next() {
			if ((this.page + 1) * this.limit < this.total) { this.page++; this.load() }
		},
	},
}
</script>

<style scoped>
.records-view {
	padding: 12px 20px 32px;
}

.toolbar {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 14px;
	flex-wrap: wrap;
}

.search {
	max-width: 320px;
}

.import-help {
	max-width: 540px;
	font-size: 0.95em;
}

.import-help ul {
	margin: 8px 0 8px 18px;
	list-style: disc;
}

.import-help li {
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.import-help .tip {
	color: var(--color-text-maxcontrast);
}

.import-result {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.import-result .err-row {
	color: var(--color-error-text, var(--color-error));
	font-size: 0.88em;
}

.hidden-file {
	display: none;
}

.visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

.spacer {
	flex: 1;
}

.centered {
	margin: 60px auto;
}

/* A subtle affordance that a cell can be edited in place (double-click). */

/* Subtle zebra striping aids row-tracking across a wide table. */

/* Keep the row actions reachable no matter how wide the table scrolls. */

.toolbar .view-select {
	min-width: 170px;
	max-width: 240px;
}

.save-view-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: min(440px, 82vw);
	padding: 8px 0;
}

.save-view-form .hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	margin: 0;
}

.pager {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
