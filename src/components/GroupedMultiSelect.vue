<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!--
	A multi-select for long option lists that buckets options under collapsible
	parent groups (derived from a regex on the option text). Supports per-group
	select-all, a live search that filters across all options, and removable
	chips for the current selection. The emitted value is a flat array of the
	selected option strings — grouping is purely a data-entry aid.
-->
<template>
	<div class="gms" :class="{ disabled }">
		<!-- selected chips -->
		<div v-if="selected.length" class="gms-chips">
			<span v-for="val in selected" :key="val" class="gms-chip">
				{{ val }}
				<button v-if="!disabled"
					type="button"
					class="gms-chip-x"
					:aria-label="t('dataforms', 'Remove {item}', { item: val })"
					@click="toggleOption(val)">×</button>
			</span>
			<button v-if="!disabled && selected.length"
				type="button"
				class="gms-clear"
				@click="clearAll">
				{{ t('dataforms', 'Clear all') }}
			</button>
		</div>

		<!-- search -->
		<div class="gms-search">
			<input v-model="query"
				type="text"
				class="native-input"
				:disabled="disabled"
				:aria-label="t('dataforms', 'Search options')"
				:placeholder="t('dataforms', 'Search options…')">
			<span class="gms-summary">{{ n('dataforms', '%n selected', '%n selected', selected.length) }}</span>
		</div>

		<!-- grouped list -->
		<div class="gms-list" role="group" :aria-label="label">
			<div v-for="g in visibleGroups" :key="g.label" class="gms-group">
				<div class="gms-group-head">
					<button type="button"
						class="gms-toggle"
						:aria-expanded="isOpen(g.label)"
						@click="toggleOpen(g.label)">
						<span class="gms-chevron" :class="{ open: isOpen(g.label) }">▸</span>
					</button>
					<label class="gms-group-label">
						<input type="checkbox"
							:checked="g.all"
							:indeterminate.prop="g.some && !g.all"
							:disabled="disabled"
							@change="toggleGroup(g)">
						<span class="gms-group-name">{{ g.label || t('dataforms', 'Options') }}</span>
						<span class="gms-count">{{ g.selectedCount }}/{{ g.options.length }}</span>
					</label>
				</div>
				<ul v-if="isOpen(g.label)" class="gms-options">
					<li v-for="opt in g.options" :key="opt">
						<label class="gms-option">
							<input type="checkbox"
								:checked="selectedSet.has(opt)"
								:disabled="disabled"
								@change="toggleOption(opt)">
							<span>{{ opt }}</span>
						</label>
					</li>
				</ul>
			</div>
			<p v-if="visibleGroups.length === 0" class="gms-empty">
				{{ t('dataforms', 'No matching options') }}
			</p>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { groupForOption } from '../api/fields.js'

export default {
	name: 'GroupedMultiSelect',
	props: {
		options: { type: /** @type {import('vue').PropType<string[]>} */ (Array), default: () => [] },
		modelValue: { type: /** @type {import('vue').PropType<string[]>} */ (Array), default: () => [] },
		groupPattern: { type: String, default: '' },
		label: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
	data() {
		return {
			query: '',
			openGroups: {},
		}
	},
	computed: {
		selected() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
		selectedSet() {
			return new Set(this.selected)
		},
		// All options bucketed into ordered groups (first-appearance order).
		groups() {
			const order = []
			const byLabel = {}
			for (const opt of this.options) {
				const label = groupForOption(opt, this.groupPattern)
				if (!byLabel[label]) {
					byLabel[label] = []
					order.push(label)
				}
				byLabel[label].push(opt)
			}
			return order.map((label) => {
				const opts = byLabel[label]
				const selectedCount = opts.filter((o) => this.selectedSet.has(o)).length
				return {
					label,
					options: opts,
					selectedCount,
					all: selectedCount === opts.length && opts.length > 0,
					some: selectedCount > 0,
				}
			})
		},
		// Groups filtered by the search query (matching options only).
		visibleGroups() {
			const q = this.query.trim().toLowerCase()
			if (q === '') {
				return this.groups
			}
			const out = []
			for (const g of this.groups) {
				const opts = g.options.filter((o) => o.toLowerCase().includes(q)
					|| g.label.toLowerCase().includes(q))
				if (opts.length) {
					const selectedCount = opts.filter((o) => this.selectedSet.has(o)).length
					out.push({ ...g, options: opts, selectedCount, all: selectedCount === opts.length, some: selectedCount > 0 })
				}
			}
			return out
		},
	},
	watch: {
		// While searching, auto-expand groups that have matches.
		query(q) {
			if (q.trim() !== '') {
				for (const g of this.visibleGroups) {
					this.openGroups[g.label] = true
				}
			}
		},
	},
	methods: {
		t,
		n,
		isOpen(label) {
			return !!this.openGroups[label]
		},
		toggleOpen(label) {
			this.openGroups = { ...this.openGroups, [label]: !this.openGroups[label] }
		},
		toggleOption(opt) {
			if (this.disabled) {
				return
			}
			const next = this.selectedSet.has(opt)
				? this.selected.filter((v) => v !== opt)
				: [...this.selected, opt]
			this.$emit('update:modelValue', next)
		},
		toggleGroup(g) {
			if (this.disabled) {
				return
			}
			const set = this.selectedSet
			let next
			if (g.all) {
				// deselect every option in this group
				const remove = new Set(g.options)
				next = this.selected.filter((v) => !remove.has(v))
			} else {
				next = [...this.selected]
				for (const o of g.options) {
					if (!set.has(o)) {
						next.push(o)
					}
				}
			}
			this.$emit('update:modelValue', next)
		},
		clearAll() {
			this.$emit('update:modelValue', [])
		},
	},
}
</script>

<style scoped>
.gms {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	overflow: hidden;
}

.gms.disabled {
	opacity: 0.6;
}

.gms-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.gms-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
	border-radius: 14px;
	padding: 2px 6px 2px 10px;
	font-size: 0.86em;
}

.gms-chip-x {
	border: none;
	background: none;
	cursor: pointer;
	font-size: 1.1em;
	line-height: 1;
	color: inherit;
	padding: 0 2px;
}

.gms-clear {
	border: none;
	background: none;
	color: var(--color-primary-element);
	cursor: pointer;
	font-size: 0.86em;
	margin-inline-start: auto;
}

.gms-search {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px;
}

.gms-search .native-input {
	flex: 1;
	min-height: 36px;
	padding: 6px 10px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius, 6px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}

.gms-summary {
	color: var(--color-text-maxcontrast);
	font-size: 0.86em;
	white-space: nowrap;
}

.gms-list {
	max-height: 320px;
	overflow-y: auto;
	padding: 4px 0;
}

.gms-group-head {
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 2px 8px;
}

.gms-toggle {
	border: none;
	background: none;
	cursor: pointer;
	padding: 4px;
	color: var(--color-text-maxcontrast);
}

.gms-chevron {
	display: inline-block;
	transition: transform 0.12s ease;
}

.gms-chevron.open {
	transform: rotate(90deg);
}

.gms-group-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	flex: 1;
	font-weight: 600;
}

.gms-count {
	margin-inline-start: auto;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
}

.gms-options {
	list-style: none;
	margin: 0;
	padding: 0 8px 6px 34px;
}

.gms-option {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 3px 0;
	cursor: pointer;
}

.gms-empty {
	padding: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
