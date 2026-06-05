<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Read-only view of a single record: all fields, including relations. -->
<template>
	<NcDialog
		:name="t('dataforms', 'Record details')"
		size="normal"
		@closing="$emit('close')">
		<dl class="detail">
			<template v-for="field in fields" :key="field.id">
				<dt>{{ field.label }}</dt>
				<dd>
					<span v-if="isRelation(field) && !isEmpty(value(field))" class="relation">
						{{ relationLabels(value(field)) }}
					</span>
					<template v-else-if="field.type === 'file'">
						<span v-if="fileItems(field).length === 0" class="empty">—</span>
						<ul v-else class="file-list">
							<li v-for="f in fileItems(field)" :key="f.id">
								<a :href="fileUrl(f.id)" target="_blank" rel="noopener noreferrer">📎 {{ f.name }}</a>
							</li>
						</ul>
					</template>
					<span v-else-if="isEmpty(value(field))" class="empty">—</span>
					<span v-else>{{ display(field, value(field)) }}</span>
				</dd>
			</template>
		</dl>
		<template #actions>
			<NcButton @click="$emit('close')">{{ t('dataforms', 'Close') }}</NcButton>
			<NcButton v-if="canEdit" type="primary" @click="$emit('edit', record)">
				{{ t('dataforms', 'Edit') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

export default {
	name: 'RecordDetail',
	components: { NcButton, NcDialog },
	props: {
		fields: { type: Array, required: true },
		record: { type: Object, required: true },
		canEdit: { type: Boolean, default: false },
	},
	emits: ['close', 'edit'],
	methods: {
		t,
		value(field) {
			return this.record.values[field.machineName]
		},
		isRelation(field) {
			return field.type === 'relation'
		},
		relationLabels(v) {
			const list = Array.isArray(v) ? v : [v]
			return list.filter(Boolean).map((r) => (r && typeof r === 'object' && 'label' in r) ? r.label : String(r)).join(', ')
		},
		fileItems(field) {
			const v = this.value(field)
			if (Array.isArray(v)) return v
			return v && v.id ? [v] : []
		},
		fileUrl(id) {
			return generateUrl('/f/{id}', { id })
		},
		isEmpty(v) {
			return v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)
		},
		display(field, v) {
			if (['number', 'currency', 'percentage'].includes(field.type) && v !== '' && v !== null && !isNaN(Number(v))) {
				const dec = field.config?.decimals ?? (field.type === 'currency' ? 2 : 0)
				return Number(v).toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec })
			}
			if (Array.isArray(v)) return v.join(', ')
			if (typeof v === 'boolean') return v ? t('dataforms', 'Yes') : t('dataforms', 'No')
			if (v && typeof v === 'object' && 'label' in v) return v.label
			return String(v)
		},
	},
}
</script>

<style scoped>
.detail {
	display: grid;
	grid-template-columns: minmax(140px, 220px) 1fr;
	column-gap: 24px;
	align-items: start;
	min-width: min(560px, 84vw);
	padding: 4px 2px;
}

.detail dt {
	color: var(--color-text-maxcontrast);
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
	font-size: 0.9em;
	/* Long labels wrap within their column instead of spilling over the value. */
	overflow-wrap: anywhere;
	hyphens: auto;
}

.detail dd {
	margin: 0;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
	font-weight: 500;
	word-break: break-word;
	min-width: 0;
}

.relation {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	border-radius: 12px;
	padding: 2px 10px;
	font-size: 0.9em;
}

.empty {
	color: var(--color-text-maxcontrast);
}
.file-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
</style>
