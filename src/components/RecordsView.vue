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
			<span class="spacer" />
			<input ref="importInput" type="file" accept=".csv,text/csv" class="hidden-file" @change="onImportFile">
			<NcButton :disabled="fields.length === 0 || importing" @click="$refs.importInput.click()">
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
			<NcButton type="primary" :disabled="fields.length === 0" @click="openNew">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dataforms', 'New record') }}
			</NcButton>
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
							<th v-for="field in columns" :key="field.id">{{ field.label }}</th>
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
									<NcActionButton @click="openEdit(record)">
										<template #icon>
											<PencilIcon :size="20" />
										</template>
										{{ t('dataforms', 'Edit') }}
									</NcActionButton>
									<NcActionButton @click="remove(record)">
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
			@edit="onDetailEdit"
			@close="showDetail = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'

import RecordForm from './RecordForm.vue'
import RecordDetail from './RecordDetail.vue'
import { listRecords, deleteRecord, csvExportUrl, importCsv } from '../api/records.js'
import { listRules } from '../api/rules.js'

export default {
	name: 'RecordsView',
	components: {
		NcActions, NcActionButton, NcButton, NcEmptyContent, NcLoadingIcon, NcTextField,
		PlusIcon, PencilIcon, DeleteIcon, DownloadIcon, UploadIcon, TableIcon, RecordForm, RecordDetail,
	},
	props: {
		registerId: { type: Number, required: true },
	},
	data() {
		return {
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
			searchTimer: null,
		}
	},
	computed: {
		columns() {
			return this.fields.slice(0, 6)
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
				const data = await listRecords(this.registerId, {
					limit: this.limit,
					offset: this.page * this.limit,
					search: this.search,
				})
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
			if (Array.isArray(value)) return value.join(', ')
			if (typeof value === 'boolean') return value ? t('dataforms', 'Yes') : t('dataforms', 'No')
			if (typeof value === 'object' && 'label' in value) return value.label // relation
			return String(value)
		},
		openNew() {
			this.editing = null
			this.showForm = true
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
			try {
				const csv = await file.text()
				const result = await importCsv(this.registerId, csv)
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
