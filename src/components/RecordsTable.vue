<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- The records grid with in-place cell editing (extracted from RecordsView,
     #27). Owns the inline-edit state and the per-cell save; sorting, row
     actions and detail/edit/delete are emitted to the parent. UI behaviour and
     appearance are unchanged. -->
<template>
	<div class="table-wrap">
		<table :aria-label="t('dataforms', 'Records')">
			<thead>
				<tr>
					<th v-for="field in columns"
						:key="field.id"
						scope="col"
						class="sortable"
						role="button"
						tabindex="0"
						:aria-sort="ariaSort(field)"
						@click="$emit('sort', field)"
						@keydown.enter.prevent="$emit('sort', field)"
						@keydown.space.prevent="$emit('sort', field)">
						{{ field.label }}
						<span v-if="sort === field.machineName" class="sort-ind" aria-hidden="true">{{ direction === 'ASC' ? '▲' : '▼' }}</span>
					</th>
					<th scope="col" class="actions-col">
						<span class="visually-hidden">{{ t('dataforms', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="record in records" :key="record.id">
					<td v-for="field in columns"
						:key="field.id"
						:class="{ editable: canModify(record) && editable(field), editing: isEditingCell(record, field) }"
						:tabindex="canModify(record) && editable(field) ? 0 : undefined"
						@click="onCellClick(record, field)"
						@dblclick="onCellDblClick(record, field)"
						@keydown.enter.prevent="!isEditingCell(record, field) && canModify(record) && editable(field) && onCellDblClick(record, field)">
						<template v-if="isEditingCell(record, field)">
							<select v-if="field.type === 'select'"
								ref="inlineInput"
								v-model="editValue"
								class="inline-input"
								:aria-label="t('dataforms', 'Edit {field}', { field: field.label })"
								@change="saveInline(record, field)"
								@keydown.esc="cancelInline"
								@blur="saveInline(record, field)">
								<option value="" />
								<option v-for="o in (field.config?.options || [])" :key="o" :value="o">
									{{ o }}
								</option>
							</select>
							<select v-else-if="field.type === 'boolean'"
								ref="inlineInput"
								v-model="editValue"
								class="inline-input"
								:aria-label="t('dataforms', 'Edit {field}', { field: field.label })"
								@change="saveInline(record, field)"
								@keydown.esc="cancelInline"
								@blur="saveInline(record, field)">
								<option value="" />
								<option value="true">
									{{ t('dataforms', 'Yes') }}
								</option>
								<option value="false">
									{{ t('dataforms', 'No') }}
								</option>
							</select>
							<input v-else
								ref="inlineInput"
								v-model="editValue"
								:type="inputType(field)"
								class="inline-input"
								:aria-label="t('dataforms', 'Edit {field}', { field: field.label })"
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
							<NcActionButton @click="$emit('detail', record)">
								<template #icon>
									<EyeIcon :size="20" />
								</template>
								{{ t('dataforms', 'View details') }}
							</NcActionButton>
							<NcActionButton v-if="canModify(record)" @click="$emit('edit', record)">
								<template #icon>
									<PencilIcon :size="20" />
								</template>
								{{ t('dataforms', 'Edit') }}
							</NcActionButton>
							<NcActionButton v-if="canModify(record)" @click="$emit('delete', record)">
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
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'

import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'

import { updateRecord } from '../api/records.js'
import { isInlineEditable, inlineInputType, seedInlineValue, coerceInlineValue, formatCell } from './records/cells.js'

export default {
	name: 'RecordsTable',
	components: { NcActions, NcActionButton, PencilIcon, DeleteIcon, EyeIcon },
	props: {
		records: { type: /** @type {import('vue').PropType<import('@/types/models').RecordRow[]>} */ (Array), required: true },
		columns: { type: /** @type {import('vue').PropType<import('@/types/models').Field[]>} */ (Array), required: true },
		canManage: { type: Boolean, default: false },
		currentUserId: { type: String, default: '' },
		sort: { type: String, default: 'updated' },
		direction: { type: String, default: 'DESC' },
	},
	emits: ['sort', 'detail', 'edit', 'delete', 'inline-saved', 'reload', 'editing-change'],
	data() {
		return {
			/** @type {{recordId:number,machineName:string}|null} */
			editingCell: null,
			editValue: '',
			/** @type {any} */
			clickTimer: null,
		}
	},
	beforeUnmount() {
		clearTimeout(this.clickTimer)
	},
	methods: {
		t,
		format(field, value) {
			return formatCell(field, value, t)
		},
		editable(field) {
			return isInlineEditable(field)
		},
		inputType(field) {
			return inlineInputType(field)
		},
		// A user may edit a record they created, or any record if they manage the
		// register. Mirrors the server-side rule.
		canModify(record) {
			return this.canManage || record.createdBy === this.currentUserId
		},
		ariaSort(field) {
			if (this.sort !== field.machineName) {
				return 'none'
			}
			return this.direction === 'ASC' ? 'ascending' : 'descending'
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
			this.clickTimer = setTimeout(() => this.$emit('detail', record), 220)
		},
		onCellDblClick(record, field) {
			clearTimeout(this.clickTimer)
			if (!this.canModify(record)) {
				this.$emit('detail', record)
				return
			}
			if (this.editable(field)) {
				this.startInline(record, field)
			} else {
				this.$emit('edit', record) // complex types: full editor
			}
		},
		startInline(record, field) {
			this.editValue = seedInlineValue(field, record.values[field.machineName])
			this.editingCell = { recordId: record.id, machineName: field.machineName }
			this.$emit('editing-change', true)
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
			this.$emit('editing-change', false)
		},
		async saveInline(record, field) {
			if (!this.editingCell) {
				return
			}
			const mn = field.machineName
			const next = coerceInlineValue(field, this.editValue)
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
				this.$emit('inline-saved', updated)
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not save the change'))
				console.error(e)
				this.$emit('reload') // re-sync from the server on failure
			}
		},
	},
}
</script>

<style scoped>
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
	text-align: start;
	font-size: 0.78em;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	color: var(--color-text-maxcontrast);
	padding: 10px 14px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark, var(--color-background-hover));
	white-space: nowrap;
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
	margin-inline-start: 4px;
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
	text-align: end;
	position: sticky;
	inset-inline-end: 0;
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
</style>
