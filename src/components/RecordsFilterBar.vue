<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- The records filter builder (extracted from RecordsView, #27). Owns the draft
     filter rows; emits the normalised criteria to the parent on Apply. UI and
     behaviour are unchanged. -->
<template>
	<div class="filter-bar">
		<div v-for="(f, i) in draftFilters" :key="i" class="filter-row">
			<NcSelect :model-value="f.field"
				:options="fieldOptions"
				:reduce="(o) => o.id"
				label="label"
				:clearable="false"
				class="f-field"
				:placeholder="t('dataforms', 'Field')"
				@update:model-value="onFieldChange(f, $event)" />
			<NcSelect v-model="f.op"
				:options="filterOps"
				:reduce="(o) => o.id"
				label="label"
				:clearable="false"
				class="f-op" />
			<NcSelect v-if="!['isEmpty', 'isNotEmpty'].includes(f.op) && valueOptions(f.field).length"
				v-model="f.value"
				:options="valueOptions(f.field)"
				:clearable="false"
				class="f-val"
				:placeholder="t('dataforms', 'Value')" />
			<NcTextField v-else-if="!['isEmpty', 'isNotEmpty'].includes(f.op)"
				v-model="f.value"
				:type="valueInputType(f.field)"
				:label="t('dataforms', 'Value')"
				class="f-val" />
			<NcButton variant="tertiary" :aria-label="t('dataforms', 'Remove')" @click="draftFilters.splice(i, 1)">
				<template #icon>
					<CloseIcon :size="18" />
				</template>
			</NcButton>
		</div>
		<div class="filter-actions">
			<NcButton variant="tertiary" @click="addCondition">
				<template #icon>
					<PlusIcon :size="18" />
				</template>
				{{ t('dataforms', 'Add condition') }}
			</NcButton>
			<span class="spacer" />
			<NcButton @click="clear">
				{{ t('dataforms', 'Clear') }}
			</NcButton>
			<NcButton variant="primary" @click="apply">
				{{ t('dataforms', 'Apply') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

import { FILTER_OPS } from '../api/rules.js'
import { filterableFieldOptions, filterValueOptions, filterValueInputType, defaultOperatorForField } from './records/filters.js'

export default {
	name: 'RecordsFilterBar',
	components: { NcButton, NcSelect, NcTextField, PlusIcon, CloseIcon },
	props: {
		fields: { type: /** @type {import('vue').PropType<import('@/types/models').Field[]>} */ (Array), required: true },
		initialFilters: { type: /** @type {import('vue').PropType<Array<{field:string,op:string,value?:any}>>} */ (Array), default: () => [] },
	},
	emits: ['apply', 'clear'],
	data() {
		return {
			filterOps: FILTER_OPS,
			/** @type {Array<{field:string,op:string,value?:any}>} */
			draftFilters: this.initialFilters.length
				? this.initialFilters.map((f) => ({ ...f }))
				: [{ field: filterableFieldOptions(this.fields)[0]?.id ?? '', op: 'eq', value: '' }],
		}
	},
	computed: {
		fieldOptions() {
			return filterableFieldOptions(this.fields)
		},
	},
	methods: {
		t,
		// Options for a select/multi-select filter value (empty for other types).
		valueOptions(machineName) {
			return filterValueOptions(this.fields, machineName)
		},
		// HTML input type for a free-text filter value (date/number where useful).
		valueInputType(machineName) {
			return filterValueInputType(this.fields, machineName)
		},
		// Reset the value and pick a sensible operator when the field changes.
		onFieldChange(f, fieldId) {
			f.field = fieldId
			f.value = ''
			f.op = defaultOperatorForField(this.fields, fieldId)
		},
		addCondition() {
			this.draftFilters.push({ field: this.fieldOptions[0]?.id ?? '', op: 'eq', value: '' })
		},
		apply() {
			const filters = this.draftFilters
				.filter((f) => f.field)
				.map((f) => ({ field: f.field, op: f.op, value: f.value }))
			this.$emit('apply', filters)
		},
		clear() {
			this.draftFilters = []
			this.$emit('clear')
		},
	},
}
</script>

<style scoped>
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
</style>
