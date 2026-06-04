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
			<span class="spacer" />
			<input ref="importInput" type="file" accept=".csv,text/csv" class="hidden-file" @change="onImportFile">
			<NcButton v-if="canWrite" :disabled="fields.length === 0 || importing" @click="showImport = true">
				<template #icon>
					<UploadIcon :size="20" />
				</template>
				{{ importing ? t('dataforms', 'Importing…') : t('dataforms', 'Import CSV') }}
			</NcButton>
			<NcButton :disabled="fields.length === 0" @click="exportCsv">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t('dataforms', 'Export CSV') }}
			</NcButton>
			<NcButton v-if="canWrite" type="primary" :disabled="fields.length === 0" @click="openNew">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dataforms', 'New record') }}
			</NcButton>
		</div>

		<div v-if="showFilter" class="filter-bar">
			<div v-for="(f, i) in draftFilters" :key="i" class="filter-row">
				<NcSelect v-model="f.field" :options="filterFieldOptions" :reduce="(o) => o.id" label="label" :clearable="false" class="f-field" :placeholder="t('dataforms', 'Field')" />
				<NcSelect v-model="f.op" :options="filterOps" :reduce="(o) => o.id" label="label" :clearable="false" class="f-op" />
				<NcTextField v-if="!['isEmpty', 'isNotEmpty'].includes(f.op)" v-model="f.value" :label="t('dataforms', 'Value')" class="f-val" />
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
						<tr v-for="record in records" :key="record.id" @click="openDetail(record)">
							<td v-for="field in columns" :key="field.id">
								{{ format(field, record.values[field.machineName]) }}
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
import NcButton from '@nextcloud/vue/components/NcButton'
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
import EyeIcon from 'vue-material-design-icons/Eye.vue'

import RecordForm from './RecordForm.vue'
import RecordDetail from './RecordDetail.vue'
import { listRecords, deleteRecord, csvExportUrl, importCsv } from '../api/records.js'
import { listRules, FILTER_OPS } from '../api/rules.js'

export default {
	name: 'RecordsView',
	components: {
		NcActions, NcActionButton, NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField,
		PlusIcon, FilterIcon, CloseIcon, PencilIcon, DeleteIcon, DownloadIcon, UploadIcon, TableIcon, EyeIcon, RecordForm, RecordDetail,
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
		}
	},
	computed: {
		columns() {
			return this.fields.slice(0, 6)
		},
		filterFieldOptions() {
			return this.fields
				.filter((f) => !['file', 'relation'].includes(f.type))
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
			this.reload()
		},
	},
	async mounted() {
		this.rules = await listRules(this.registerId).catch(() => [])
		await this.load()
	},
	methods: {
		t,
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
			if (Array.isArray(value)) return value.join(', ') // multiselect
			if (typeof value === 'boolean') return value ? t('dataforms', 'Yes') : t('dataforms', 'No')
			if (typeof value === 'object' && 'label' in value) return value.label // relation
			return String(value)
		},
		openNew() {
			this.editing = null
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
			this.showForm = true
		},
		openDetail(record) {
			this.detailRecord = record
			this.showDetail = true
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
				showError(t('dataforms', 'Could not delete the record'))
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
	padding: 16px 24px 40px;
}

.toolbar {
	display: flex;
	align-items: flex-end;
	gap: 10px;
	margin-bottom: 16px;
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
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

table {
	width: 100%;
	border-collapse: collapse;
}

thead th {
	text-align: left;
	font-size: 0.78em;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	color: var(--color-text-maxcontrast);
	padding: 10px 14px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
	white-space: nowrap;
}

tbody td {
	padding: 10px 14px;
	border-bottom: 1px solid var(--color-border);
	max-width: 280px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

tbody tr {
	cursor: pointer;
}

tbody tr:hover {
	background: var(--color-background-hover);
}

tbody tr:last-child td {
	border-bottom: none;
}

.actions-col {
	width: 50px;
	text-align: right;
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
