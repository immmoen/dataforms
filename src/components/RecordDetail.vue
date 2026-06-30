<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Read-only view of a single record: all fields, including relations. -->
<template>
	<NcDialog :name="t('dataforms', 'Record details')"
		size="normal"
		@closing="$emit('close')">
		<dl class="detail">
			<!-- eslint-disable-next-line vue/no-v-for-template-key -- a key on <template v-for> wrapping a <dt>/<dd> pair is valid and idiomatic in Vue 3 -->
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
								<a :href="fileUrl(f.id)" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">📎 </span>{{ f.name }}</a>
							</li>
						</ul>
					</template>
					<span v-else-if="isEmpty(value(field))" class="empty">—</span>
					<span v-else>{{ display(field, value(field)) }}</span>
				</dd>
			</template>
		</dl>

		<!-- Audit history -->
		<div class="history">
			<button type="button"
				class="history-toggle"
				:aria-expanded="showHistory"
				aria-controls="df-record-history"
				@click="toggleHistory">
				<HistoryIcon :size="18" aria-hidden="true" />
				{{ t('dataforms', 'History') }}
				<span class="chev" :class="{ open: showHistory }" aria-hidden="true">▸</span>
			</button>
			<div v-if="showHistory"
				id="df-record-history"
				class="history-body"
				role="region"
				:aria-label="t('dataforms', 'History')"
				aria-live="polite">
				<NcLoadingIcon v-if="historyLoading" :size="22" />
				<p v-else-if="history.length === 0" class="empty">
					{{ t('dataforms', 'No history recorded.') }}
				</p>
				<ul v-else class="timeline">
					<li v-for="h in history" :key="h.id" class="event">
						<span class="dot"
							:class="'dot-' + h.action"
							role="img"
							:aria-label="h.action" />
						<div class="event-main">
							<div class="event-summary">
								{{ h.summary }}
							</div>
							<div v-if="h.detail && h.detail.fields" class="event-detail">
								{{ h.detail.fields.join(', ') }}
							</div>
							<div class="event-meta">
								{{ h.user }} · {{ formatTime(h.created) }}
							</div>
						</div>
					</li>
				</ul>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('dataforms', 'Close') }}
			</NcButton>
			<NcButton v-if="canEdit" variant="primary" @click="$emit('edit', record)">
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import { listHistory } from '../api/records.js'

export default {
	name: 'RecordDetail',
	components: { NcButton, NcDialog, NcLoadingIcon, HistoryIcon },
	props: {
		fields: { type: /** @type {import('vue').PropType<import('@/types/models').Field[]>} */ (Array), required: true },
		record: { type: Object, required: true },
		canEdit: { type: Boolean, default: false },
	},
	emits: ['close', 'edit'],
	data() {
		return {
			showHistory: false,
			/** @type {Array<{id?:number,action:string,user:string,summary:string,detail:any,created:number}>} */
			history: [],
			historyLoading: false,
		}
	},
	methods: {
		async toggleHistory() {
			this.showHistory = !this.showHistory
			if (this.showHistory && this.history.length === 0) {
				this.historyLoading = true
				try {
					this.history = await listHistory(this.record.id)
				} catch (e) {
					console.error(e)
				} finally {
					this.historyLoading = false
				}
			}
		},
		formatTime(ts) {
			if (!ts) return ''
			try {
				return new Date(ts * 1000).toLocaleString()
			} catch (e) {
				return ''
			}
		},
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
	overflow-wrap: break-word;
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

.history {
	margin-top: 14px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.history-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-main-text);
	font-weight: 600;
	font-size: 0.92em;
	padding: 4px 2px;
}

.history-toggle .chev {
	transition: transform 0.12s ease;
	color: var(--color-text-maxcontrast);
}

.history-toggle .chev.open {
	transform: rotate(90deg);
}

.history-body {
	padding: 6px 2px 2px;
}

.timeline {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 4px 0 0;
}

.event {
	display: flex;
	gap: 10px;
	align-items: flex-start;
}

.dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-top: 4px;
	flex: none;
	background: var(--color-primary-element);
}

.dot-create { background: var(--color-success, #4f7355); }

.dot-update { background: var(--color-primary-element); }

.dot-delete { background: var(--color-error, #9d3a3a); }

.event-summary {
	font-weight: 500;
	font-size: 0.9em;
}

.event-detail {
	color: var(--color-text-maxcontrast);
	font-size: 0.84em;
}

.event-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	margin-top: 1px;
}
</style>
