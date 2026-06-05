<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="records-view">
		<div class="toolbar">
			<NcTextField
				v-model="search"
				class="search"
				:label="t('dataforms', 'Search records')"
				label-visible
				type="search"
				@update:model-value="onSearch" />
			<NcButton :type="activeFilters.length ? 'secondary' : 'tertiary'" @click="toggleFilterBar">
				<template #icon><FilterIcon :size="20" /></template>
				{{ activeFilters.length ? t('dataforms', 'Filter ({n})', { n: activeFilters.length }) : t('dataforms', 'Filter') }}
			</NcButton>
			<NcButton type="tertiary" :aria-label="t('dataforms', 'Refresh')" :title="t('dataforms', 'Refresh')" @click="load()">
				<template #icon><RefreshIcon :size="20" /></template>
			</NcButton>
			<span class="spacer" />
			<input ref="importInput" type="file" accept=".csv,text/csv" class="hidden-file" @change="onImportFile">

			<!-- Secondary, less-frequent actions live behind one tidy menu. -->
			<NcActions :menu-name="t('dataforms', 'More')" :force-name="false">
				<template #icon><DotsIcon :size="20" /></template>
				<NcActionButton v-if="canWrite" :disabled="fields.length === 0 || importing" close-after-click @click="showImport = true">
					<template #icon><UploadIcon :size="20" /></template>
					{{ importing ? t('dataforms', 'Importing…') : t('dataforms', 'Import from CSV…') }}
				</NcActionButton>
				<NcActionButton :disabled="fields.length === 0 || records.length === 0" close-after-click @click="exportCsv">
					<template #icon><DownloadIcon :size="20" /></template>
					{{ t('dataforms', 'Export to CSV') }}
				</NcActionButton>
			</NcActions>

			<NcActions
				v-if="canWrite && forms.length"
				type="primary"
				:menu-name="t('dataforms', 'New record')"
				:disabled="fields.length === 0">
				<template #icon><PlusIcon :size="20" /></template>
				<NcActionButton @click="openNew(null)">
					{{ t('dataforms', 'Blank (all fields)') }}
				</NcActionButton>
				<NcActionButton v-for="f in forms" :key="f.id" @click="openNew(f)">
					{{ f.title }}
				</NcActionButton>
			</NcActions>
			<NcButton v-else-if="canWrite" type="primary" :disabled="fields.length === 0" @click="openNew(null)">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dataforms', 'New record') }}
			</NcButton>
		</div>

		<div v-if="fields.length" class="views-bar">
			<NcSelect
				:model-value="activeView"
				:options="viewOptions"
				label="title"
				:clearable="true"
				:placeholder="t('dataforms', 'All records')"
				class="view-select"
				@update:model-value="onSelectView" />
			<NcButton type="tertiary" @click="openSaveView">
				<template #icon><ContentSaveIcon :size="18" /></template>
				{{ t('dataforms', 'Save as view') }}
			</NcButton>
			<NcButton v-if="activeView && activeView.isOwner" type="tertiary" @click="removeActiveView">
				<template #icon><DeleteIcon :size="18" /></template>
				{{ t('dataforms', 'Delete view') }}
			</NcButton>
			<span class="spacer" />
			<NcActions :menu-name="t('dataforms', 'Columns')" :primary="false">
				<template #icon><TableColumnIcon :size="20" /></template>
				<NcActionCheckbox
					v-for="field in fields"
					:key="field.id"
					:model-value="isColumnVisible(field)"
					@update:model-value="toggleColumn(field)">
					{{ field.label }}
				</NcActionCheckbox>
			</NcActions>
		</div>

		<div v-if="showFilter" class="filter-bar">
			<div v-for="(f, i) in draftFilters" :key="i" class="filter-row">
				<NcSelect :model-value="f.field" :options="filterFieldOptions" :reduce="(o) => o.id" label="label" :clearable="false" class="f-field" :placeholder="t('dataforms', 'Field')" @update:model-value="onFilterFieldChange(f, $event)" />
				<NcSelect v-model="f.op" :options="filterOps" :reduce="(o) => o.id" label="label" :clearable="false" class="f-op" />
				<NcSelect
					v-if="!['isEmpty', 'isNotEmpty'].includes(f.op) && fieldOptions(f.field).length"
					v-model="f.value"
					:options="fieldOptions(f.field)"
					:clearable="false"
					class="f-val"
					:placeholder="t('dataforms', 'Value')" />
				<NcTextField
					v-else-if="!['isEmpty', 'isNotEmpty'].includes(f.op)"
					v-model="f.value"
					:type="fieldInputType(f.field)"
					:label="t('dataforms', 'Value')"
					class="f-val" />
				<NcButton type="tertiary" :aria-label="t('dataforms', 'Remove')" @click="draftFilters.splice(i, 1)">
					<template #icon><CloseIcon :size="18" /></template>
				</NcButton>
			</div>
			<div class="filter-actions">
				<NcButton type="tertiary" @click="addFilter">
					<template #icon><PlusIcon :size="18" /></template>
					{{ t('dataforms', 'Add condition') }}
				</NcButton>
				<span class="spacer" />
				<NcButton @click="clearFilters">{{ t('dataforms', 'Clear') }}</NcButton>
				<NcButton type="primary" @click="applyFilters">{{ t('dataforms', 'Apply') }}</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" class="centered" :size="32" />

		<NcEmptyContent
			v-else-if="fields.length === 0"
			:name="t('dataforms', 'Define fields first')"
			:description="t('dataforms', 'This register has no fields yet. Add some in the Fields tab, then you can enter records.')">
			<template #icon>
				<TableIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="records.length === 0"
			:name="t('dataforms', 'No records yet')"
			:description="t('dataforms', 'Add the first record with the New record button.')">
			<template #icon>
				<TableIcon :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="table-wrap">
				<table>
					<thead>
						<tr>
							<th v-for="field in columns" :key="field.id" class="sortable" @click="toggleSort(field)">
								{{ field.label }}
								<span v-if="sort === field.machineName" class="sort-ind">{{ direction === 'ASC' ? '▲' : '▼' }}</span>
							</th>
							<th class="actions-col" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="record in records" :key="record.id">
							<td
								v-for="field in columns"
								:key="field.id"
								:class="{ editable: canModify(record) && isInlineEditable(field), editing: isEditingCell(record, field) }"
								@click="onCellClick(record, field)"
								@dblclick="onCellDblClick(record, field)">
								<template v-if="isEditingCell(record, field)">
									<select
										v-if="field.type === 'select'"
										ref="inlineInput"
										v-model="editValue"
										class="inline-input"
										@change="saveInline(record, field)"
										@keydown.esc="cancelInline"
										@blur="saveInline(record, field)">
										<option value="" />
										<option v-for="o in (field.config.options || [])" :key="o" :value="o">{{ o }}</option>
									</select>
									<select
										v-else-if="field.type === 'boolean'"
										ref="inlineInput"
										v-model="editValue"
										class="inline-input"
										@change="saveInline(record, field)"
										@keydown.esc="cancelInline"
										@blur="saveInline(record, field)">
										<option value="" />
										<option value="true">{{ t('dataforms', 'Yes') }}</option>
										<option value="false">{{ t('dataforms', 'No') }}</option>
									</select>
									<input
										v-else
										ref="inlineInput"
										v-model="editValue"
										:type="inlineInputType(field)"
										class="inline-input"
										@keydown.enter="saveInline(record, field)"
										@keydown.esc="cancelInline"
										@blur="saveInline(record, field)">
								</template>
								<template v-else>
									{{ format(field, record.values[field.machineName]) }}
								</template>
							</td>
							<td class="actions-col" @click.stop>
								<NcActions>
									<NcActionButton @click="openDetail(record)">
										<template #icon>
											<EyeIcon :size="20" />
										</template>
										{{ t('dataforms', 'View details') }}
									</NcActionButton>
									<NcActionButton v-if="canModify(record)" @click="openEdit(record)">
										<template #icon>
											<PencilIcon :size="20" />
										</template>
										{{ t('dataforms', 'Edit') }}
									</NcActionButton>
									<NcActionButton v-if="canModify(record)" @click="remove(record)">
										<template #icon>
											<DeleteIcon :size="20" />
										</template>
										{{ t('dataforms', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="pager">
				<span>{{ rangeLabel }}</span>
				<NcButton :disabled="page === 0" @click="prev">{{ t('dataforms', 'Previous') }}</NcButton>
				<NcButton :disabled="(page + 1) * limit >= total" @click="next">{{ t('dataforms', 'Next') }}</NcButton>
			</div>
		</template>

		<RecordForm
			v-if="showForm"
			:register-id="registerId"
			:fields="fields"
			:rules="rules"
			:record="editing"
			:form="activeForm"
			@saved="onSaved"
			@close="showForm = false" />

		<RecordDetail
			v-if="showDetail"
			:fields="fields"
			:record="detailRecord"
			:can-edit="canModify(detailRecord)"
			@edit="onDetailEdit"
			@close="showDetail = false" />

		<NcDialog
			v-if="showSaveView"
			:name="t('dataforms', 'Save as view')"
			size="normal"
			@closing="showSaveView = false">
			<div class="save-view-form">
				<NcTextField v-model="newView.title" :label="t('dataforms', 'View name')" :required="true" />
				<p class="hint">{{ t('dataforms', 'Saves the current columns, filters, sort and search.') }}</p>
				<NcCheckboxRadioSwitch v-model="newView.shared">
					{{ t('dataforms', 'Share with everyone who can see this register') }}
				</NcCheckboxRadioSwitch>
			</div>
			<template #actions>
				<NcButton @click="showSaveView = false">{{ t('dataforms', 'Cancel') }}</NcButton>
				<NcButton type="primary" :disabled="newView.title.trim() === ''" @click="saveView">{{ t('dataforms', 'Save') }}</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			v-if="showImport"
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
						<li v-for="(err, i) in importResult.errors" :key="i" class="err-row">{{ err }}</li>
					</ul>
				</div>
			</div>
			<template #actions>
				<NcButton @click="downloadTemplate">{{ t('dataforms', 'Download template') }}</NcButton>
				<NcButton type="primary" :disabled="importing" @click="$refs.importInput.click()">
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import FilterIcon from 'vue-material-design-icons/Filter.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import TableColumnIcon from 'vue-material-design-icons/TableColumn.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import DotsIcon from 'vue-material-design-icons/DotsHorizontal.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'

import RecordForm from './RecordForm.vue'
import RecordDetail from './RecordDetail.vue'
import { listRecords, deleteRecord, updateRecord, csvExportUrl, importCsv } from '../api/records.js'
import { listRules, FILTER_OPS } from '../api/rules.js'
import { listViews, createView, deleteView } from '../api/views.js'
import { listForms } from '../api/forms.js'

export default {
	name: 'RecordsView',
	components: {
		NcActions, NcActionButton, NcActionCheckbox, NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField,
		PlusIcon, FilterIcon, CloseIcon, PencilIcon, DeleteIcon, DownloadIcon, UploadIcon, TableIcon, TableColumnIcon, ContentSaveIcon, EyeIcon, DotsIcon, RefreshIcon, RecordForm, RecordDetail,
	},
	props: {
		registerId: { type: Number, required: true },
		canWrite: { type: Boolean, default: false },
		canManage: { type: Boolean, default: false },
	},
	data() {
		return {
			currentUserId: getCurrentUser()?.uid ?? '',
			records: [],
			fields: [],
			rules: [],
			total: 0,
			loading: true,
			search: '',
			page: 0,
			limit: 25,
			showForm: false,
			editing: null,
			showDetail: false,
			detailRecord: null,
			importing: false,
			showImport: false,
			importResult: null,
			searchTimer: null,
			sort: 'updated',
			direction: 'DESC',
			showFilter: false,
			draftFilters: [],
			activeFilters: [],
			filterOps: FILTER_OPS,
			views: [],
			activeViewId: null,
			visibleColumns: [],
			showSaveView: false,
			newView: { title: '', shared: false },
			forms: [],
			activeForm: null,
			editingCell: null,
			editValue: '',
			clickTimer: null,
		}
	},
	computed: {
		columns() {
			if (this.visibleColumns.length) {
				return this.visibleColumns
					.map((mn) => this.fields.find((f) => f.machineName === mn))
					.filter(Boolean)
			}
			return this.fields.slice(0, 6)
		},
		viewOptions() {
			return this.views.map((v) => ({ id: v.id, title: v.title, isOwner: v.isOwner, definition: v.definition }))
		},
		activeView() {
			return this.viewOptions.find((v) => v.id === this.activeViewId) ?? null
		},
		filterFieldOptions() {
			// 'auto' values are computed at read time (no stored column to filter);
			// file/relation live in join tables. Everything else is filterable.
			return this.fields
				.filter((f) => !['file', 'relation', 'auto'].includes(f.type))
				.map((f) => ({ id: f.machineName, label: f.label }))
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
			this.draftFilters = []
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
	},
	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.onVisible)
		window.removeEventListener('focus', this.onWindowFocus)
		clearTimeout(this.clickTimer)
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
			if (this.loading || this.showForm || this.showDetail || this.showImport || this.editingCell) {
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
			if (this.showFilter && this.draftFilters.length === 0) {
				this.draftFilters = this.activeFilters.length
					? this.activeFilters.map((f) => ({ ...f }))
					: [{ field: this.filterFieldOptions[0]?.id ?? '', op: 'eq', value: '' }]
			}
		},
		// Options for a select/multi-select filter value (empty for other types).
		fieldOptions(machineName) {
			const f = this.fields.find((x) => x.machineName === machineName)
			return (f && ['select', 'multiselect'].includes(f.type)) ? (f.config?.options ?? []) : []
		},
		// HTML input type for a free-text filter value (date/number where useful).
		fieldInputType(machineName) {
			const f = this.fields.find((x) => x.machineName === machineName)
			if (!f) return 'text'
			if (['number', 'currency', 'percentage'].includes(f.type)) return 'number'
			if (f.type === 'date') return 'date'
			return 'text'
		},
		// Reset the value and pick a sensible operator when the field changes.
		onFilterFieldChange(f, fieldId) {
			f.field = fieldId
			const fl = this.fields.find((x) => x.machineName === fieldId)
			f.value = ''
			// Multi-select values are stored as a JSON array, so match by 'contains'.
			f.op = (fl && fl.type === 'multiselect') ? 'contains' : 'eq'
		},
		// ---- saved views ----
		isColumnVisible(field) {
			return this.visibleColumns.length
				? this.visibleColumns.includes(field.machineName)
				: this.fields.slice(0, 6).some((f) => f.id === field.id)
		},
		toggleColumn(field) {
			const base = this.visibleColumns.length
				? [...this.visibleColumns]
				: this.fields.slice(0, 6).map((f) => f.machineName)
			const i = base.indexOf(field.machineName)
			if (i === -1) {
				base.push(field.machineName)
			} else {
				base.splice(i, 1)
			}
			this.visibleColumns = base
		},
		onSelectView(view) {
			if (!view) {
				this.activeViewId = null
				return
			}
			this.activeViewId = view.id
			const d = view.definition ?? {}
			this.visibleColumns = Array.isArray(d.columns) ? d.columns : []
			this.activeFilters = Array.isArray(d.filters) ? d.filters : []
			this.search = d.search ?? ''
			this.sort = d.sort ?? 'updated'
			this.direction = d.direction ?? 'DESC'
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
					definition: {
						columns: this.columns.map((f) => f.machineName),
						filters: this.activeFilters,
						sort: this.sort,
						direction: this.direction,
						search: this.search,
					},
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
			if (!this.activeView || !window.confirm(t('dataforms', 'Delete this view?'))) {
				return
			}
			const id = this.activeViewId
			try {
				await deleteView(id)
				this.views = this.views.filter((v) => v.id !== id)
				this.activeViewId = null
			} catch (e) {
				showError(t('dataforms', 'Could not delete the view'))
				console.error(e)
			}
		},
		addFilter() {
			this.draftFilters.push({ field: this.filterFieldOptions[0]?.id ?? '', op: 'eq', value: '' })
		},
		applyFilters() {
			this.activeFilters = this.draftFilters
				.filter((f) => f.field)
				.map((f) => ({ field: f.field, op: f.op, value: f.value }))
			this.page = 0
			this.load()
		},
		clearFilters() {
			this.draftFilters = []
			this.activeFilters = []
			this.page = 0
			this.load()
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
		format(field, value) {
			if (value === null || value === undefined) return ''
			if (field.type === 'file') {
				const list = Array.isArray(value) ? value : (value && value.id ? [value] : [])
				if (list.length === 0) return ''
				return list.length === 1 ? '📎 ' + list[0].name : '📎 ' + t('dataforms', '{n} files', { n: list.length })
			}
			if (field.type === 'relation') {
				const list = Array.isArray(value) ? value : [value]
				return list.filter(Boolean).map((v) => (v && typeof v === 'object' && 'label' in v) ? v.label : String(v)).join(', ')
			}
			if (['number', 'currency', 'percentage'].includes(field.type) && value !== '' && !isNaN(Number(value))) {
				const dec = field.config?.decimals ?? (field.type === 'currency' ? 2 : 0)
				return Number(value).toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec })
			}
			if (Array.isArray(value)) return value.join(', ') // multiselect
			if (typeof value === 'boolean') return value ? t('dataforms', 'Yes') : t('dataforms', 'No')
			if (typeof value === 'object' && 'label' in value) return value.label // relation
			return String(value)
		},
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
		// ---- inline cell editing -----------------------------------------
		// Simple, single-value types can be edited in place. Multi-value and
		// resolved types (relation/file/multiselect) and read-only computed/auto
		// fields fall back to the full edit dialog.
		isInlineEditable(field) {
			return ['text', 'email', 'url', 'phone', 'number', 'currency', 'percentage',
				'date', 'datetime', 'time', 'select', 'boolean'].includes(field.type)
		},
		inlineInputType(field) {
			return {
				email: 'email', url: 'url', phone: 'tel',
				number: 'number', currency: 'number', percentage: 'number',
				date: 'date', datetime: 'datetime-local', time: 'time',
			}[field.type] ?? 'text'
		},
		isEditingCell(record, field) {
			return this.editingCell
				&& this.editingCell.recordId === record.id
				&& this.editingCell.machineName === field.machineName
		},
		onCellClick(record, field) {
			if (this.editingCell) {
				return
			}
			// Defer opening the detail so a double-click (edit) can cancel it.
			clearTimeout(this.clickTimer)
			this.clickTimer = setTimeout(() => this.openDetail(record), 220)
		},
		onCellDblClick(record, field) {
			clearTimeout(this.clickTimer)
			if (!this.canModify(record)) {
				this.openDetail(record)
				return
			}
			if (this.isInlineEditable(field)) {
				this.startInline(record, field)
			} else {
				this.openEdit(record) // complex types: full editor
			}
		},
		startInline(record, field) {
			const raw = record.values[field.machineName]
			let v = raw
			if (field.type === 'boolean') {
				v = raw === true ? 'true' : (raw === false ? 'false' : '')
			} else if (raw === null || raw === undefined) {
				v = ''
			}
			this.editValue = v
			this.editingCell = { recordId: record.id, machineName: field.machineName }
			this.$nextTick(() => {
				const el = this.$refs.inlineInput
				const node = Array.isArray(el) ? el[0] : el
				node?.focus()
				node?.select?.()
			})
		},
		cancelInline() {
			this.editingCell = null
			this.editValue = ''
		},
		async saveInline(record, field) {
			if (!this.editingCell) {
				return
			}
			const mn = field.machineName
			let next = this.editValue
			if (field.type === 'boolean') {
				next = next === 'true' ? true : (next === 'false' ? false : null)
			} else if (['number', 'currency', 'percentage'].includes(field.type)) {
				next = next === '' ? null : Number(next)
			} else if (next === '') {
				next = null
			}
			const prev = record.values[mn] ?? null
			// No change → just close the editor.
			if (next === prev) {
				this.cancelInline()
				return
			}
			const payload = { ...record.values, [mn]: next }
			this.cancelInline()
			try {
				const updated = await updateRecord(record.id, payload)
				const i = this.records.findIndex((r) => r.id === record.id)
				if (i !== -1) {
					this.records.splice(i, 1, updated)
				}
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not save the change'))
				console.error(e)
				this.load() // re-sync from the server on failure
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

.spacer {
	flex: 1;
}

.centered {
	margin: 60px auto;
}

.table-wrap {
	overflow: auto;
	/* Tall viewport for many rows; the header stays pinned while scrolling. */
	max-height: calc(100vh - 320px);
	min-height: 200px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
}

table {
	width: 100%;
	border-collapse: separate;
	border-spacing: 0;
}

thead th {
	position: sticky;
	top: 0;
	z-index: 2;
	text-align: left;
	font-size: 0.78em;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	color: var(--color-text-maxcontrast);
	padding: 10px 14px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark, var(--color-background-hover));
	white-space: nowrap;
}

tbody td {
	padding: 9px 14px;
	border-bottom: 1px solid var(--color-border);
	max-width: 360px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

tbody tr {
	cursor: pointer;
}

/* A subtle affordance that a cell can be edited in place (double-click). */
td.editable:hover {
	outline: 1px dashed var(--color-primary-element);
	outline-offset: -2px;
}

td.editing {
	padding: 2px 6px;
}

.inline-input {
	width: 100%;
	min-width: 80px;
	box-sizing: border-box;
	padding: 5px 8px;
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius, 6px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}

/* Subtle zebra striping aids row-tracking across a wide table. */
tbody tr:nth-child(even) td {
	background: var(--color-background-hover);
}

tbody tr:hover td {
	background: var(--color-primary-element-light, var(--color-background-dark));
}

tbody tr:last-child td {
	border-bottom: none;
}

/* Keep the row actions reachable no matter how wide the table scrolls. */
.actions-col {
	width: 44px;
	text-align: right;
	position: sticky;
	right: 0;
	background: var(--color-main-background);
}

thead th.actions-col {
	z-index: 3;
	background: var(--color-background-dark, var(--color-background-hover));
}

tbody tr:nth-child(even) td.actions-col {
	background: var(--color-background-hover);
}

tbody tr:hover td.actions-col {
	background: var(--color-primary-element-light, var(--color-background-dark));
}

.views-bar {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
	flex-wrap: wrap;
}

.views-bar .view-select {
	min-width: 220px;
}

.views-bar .spacer {
	flex: 1;
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

.filter-bar {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px 14px;
	margin-bottom: 14px;
	background: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.filter-row {
	display: grid;
	grid-template-columns: 1.2fr 0.9fr 1.2fr auto;
	gap: 8px;
	align-items: center;
}

.filter-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 4px;
}

.filter-actions .spacer {
	flex: 1;
}

th.sortable {
	cursor: pointer;
	user-select: none;
}

th.sortable:hover {
	color: var(--color-main-text);
}

.sort-ind {
	font-size: 0.8em;
	margin-left: 4px;
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
