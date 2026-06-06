<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Data-entry form with LIVE conditional logic: fields show/hide, become
     required, and computed values recompute as you type — using the shared
     rule engine. The server re-validates everything on save. -->
<template>
	<NcDialog
		:name="record ? t('dataforms', 'Edit record') : t('dataforms', 'New record')"
		size="normal"
		:can-close="!saving"
		@closing="$emit('close')">
		<div class="record-form">
			<NcEmptyContent
				v-if="fields.length === 0"
				:name="t('dataforms', 'This register has no fields')"
				:description="t('dataforms', 'Add fields in the Fields tab first.')" />

			<template v-else>
				<div v-for="(section, si) in sections" :key="si" class="form-section">
					<h3 v-if="section.title" class="section-title">{{ section.title }}</h3>
					<div v-for="field in section.fields" :key="field.id" class="form-field">
						<div class="label-row">
							<span class="lbl">{{ field.label }}</span>
							<span v-if="evaluation.required[field.machineName]" class="req" aria-hidden="true">*</span>
							<span v-if="computedTargets.has(field.machineName)" class="computed-tag">
								{{ t('dataforms', 'computed') }}
							</span>
						</div>
						<FieldInput
							:field="field"
							:label="field.label"
							:model-value="valueFor(field)"
							:disabled="computedTargets.has(field.machineName)"
							:required="!!evaluation.required[field.machineName]"
							:invalid="!!allErrors[field.machineName]"
							:describedby="describedbyFor(field)"
							@update:model-value="onInput(field, $event)" />
						<p v-if="field.config && field.config.help" :id="'df-help-' + field.machineName" class="field-help">
							{{ field.config.help }}
						</p>
						<p v-if="allErrors[field.machineName]" :id="'df-err-' + field.machineName" class="err" role="alert">
							{{ allErrors[field.machineName] }}
						</p>
					</div>
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('dataforms', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || fields.length === 0" @click="save">
				{{ t('dataforms', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import FieldInput from './FieldInput.vue'
import { evaluateRules } from '../rules/engine.js'
import { createRecord, updateRecord } from '../api/records.js'

export default {
	name: 'RecordForm',
	components: { NcButton, NcDialog, NcEmptyContent, FieldInput },
	props: {
		registerId: { type: Number, required: true },
		fields: { type: Array, required: true },
		rules: { type: Array, default: () => [] },
		record: { type: Object, default: null },
		form: { type: Object, default: null },
	},
	emits: ['saved', 'close'],
	data() {
		return {
			values: this.initValues(),
			serverErrors: {},
			attempted: false,
			saving: false,
		}
	},
	computed: {
		evaluation() {
			return evaluateRules(this.fields, this.rules, this.values)
		},
		visibleFields() {
			return this.fields.filter((f) => this.evaluation.visible[f.machineName] !== false)
		},
		sections() {
			const visible = new Set(this.visibleFields.map((f) => f.machineName))
			const byName = {}
			for (const f of this.fields) {
				byName[f.machineName] = f
			}
			const defSections = this.form?.definition?.sections ?? []
			if (defSections.length) {
				return defSections
					.map((s) => ({
						title: s.title || '',
						fields: (s.fields || [])
							.map((mn) => byName[mn])
							.filter((f) => f && visible.has(f.machineName)),
					}))
					.filter((s) => s.fields.length > 0)
			}
			// No form: one implicit section with every visible field.
			return [{ title: '', fields: this.visibleFields }]
		},
		computedTargets() {
			return new Set(this.rules.filter((r) => r.effect === 'compute').map((r) => r.target))
		},
		allErrors() {
			// Only surface validation errors once the user has tried to save;
			// server errors (from an actual save) always show.
			return { ...(this.attempted ? this.evaluation.errors : {}), ...this.serverErrors }
		},
	},
	methods: {
		t,
		initValues() {
			const values = {}
			for (const f of this.fields) {
				if (this.record) {
					values[f.machineName] = this.record.values[f.machineName] ?? null
				} else if (f.type === 'boolean') {
					// Start unselected (neither Yes nor No) unless a default is set.
					values[f.machineName] = (f.default === 'true' || f.default === true)
						? true
						: ((f.default === 'false' || f.default === false) ? false : null)
				} else if (f.type === 'file') {
					values[f.machineName] = []
				} else if (f.type === 'relation' && f.config?.multiple) {
					values[f.machineName] = []
				} else {
					values[f.machineName] = f.default ?? (f.type === 'multiselect' ? [] : null)
				}
			}
			return values
		},
		valueFor(field) {
			// computed fields display the engine's computed value
			if (this.computedTargets.has(field.machineName)) {
				return this.evaluation.values[field.machineName]
			}
			return this.values[field.machineName]
		},
		// Space-separated ids of the help and error text shown for a field, so the
		// input's aria-describedby points a screen reader at both (WCAG 1.3.1).
		describedbyFor(field) {
			const ids = []
			if (field.config && field.config.help) {
				ids.push('df-help-' + field.machineName)
			}
			if (this.allErrors[field.machineName]) {
				ids.push('df-err-' + field.machineName)
			}
			return ids.join(' ')
		},
		onInput(field, value) {
			this.values = { ...this.values, [field.machineName]: value }
			if (this.serverErrors[field.machineName]) {
				const next = { ...this.serverErrors }
				delete next[field.machineName]
				this.serverErrors = next
			}
		},
		async save() {
			this.attempted = true
			const ev = this.evaluation
			if (Object.keys(ev.errors).length > 0) {
				showError(t('dataforms', 'Please fix the highlighted fields'))
				return
			}
			// Build the payload from computed values, but never persist a value
			// for a hidden field (its value is not meant to apply).
			const payload = {}
			for (const f of this.fields) {
				payload[f.machineName] = ev.visible[f.machineName] === false ? null : ev.values[f.machineName]
			}
			this.saving = true
			try {
				const saved = this.record
					? await updateRecord(this.record.id, payload)
					: await createRecord(this.registerId, payload)
				this.$emit('saved', saved)
			} catch (e) {
				const errors = e.response?.data?.ocs?.data?.errors
				if (errors) {
					this.serverErrors = errors
					showError(t('dataforms', 'The server rejected some values'))
				} else {
					showError(t('dataforms', 'Could not save the record'))
				}
				console.error(e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.record-form {
	display: flex;
	flex-direction: column;
	gap: 18px;
	min-width: min(520px, 82vw);
	padding: 8px 2px;
}

.label-row {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 4px;
}

.lbl {
	font-weight: 600;
	font-size: 0.92em;
}

.req {
	color: var(--color-error);
	font-weight: 700;
}

.computed-tag {
	font-size: 0.72em;
	font-weight: 600;
	padding: 1px 7px;
	border-radius: 12px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.lbl {
	color: var(--color-main-text);
}

.form-section + .form-section {
	margin-top: 10px;
}

.section-title {
	font-size: 1.05em;
	font-weight: 600;
	margin: 8px 0 4px;
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
}

.field-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.82em;
	margin: 4px 0 0;
}

.err {
	color: var(--color-error-text, var(--color-error));
	font-size: 0.85em;
	font-weight: 500;
	margin: 4px 0 0;
}
</style>
