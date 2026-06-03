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
				<div v-for="field in visibleFields" :key="field.id" class="form-field">
					<div class="label-row">
						<span class="lbl">{{ field.label }}</span>
						<span v-if="evaluation.required[field.machineName]" class="req">*</span>
						<span v-if="computedTargets.has(field.machineName)" class="computed-tag">
							{{ t('dataforms', 'computed') }}
						</span>
					</div>
					<FieldInput
						:field="field"
						:model-value="valueFor(field)"
						:disabled="computedTargets.has(field.machineName)"
						@update:model-value="onInput(field, $event)" />
					<p v-if="allErrors[field.machineName]" class="err">
						{{ allErrors[field.machineName] }}
					</p>
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
	},
	emits: ['saved', 'close'],
	data() {
		return {
			values: this.initValues(),
			serverErrors: {},
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
		computedTargets() {
			return new Set(this.rules.filter((r) => r.effect === 'compute').map((r) => r.target))
		},
		allErrors() {
			return { ...this.evaluation.errors, ...this.serverErrors }
		},
	},
	methods: {
		t,
		initValues() {
			const values = {}
			for (const f of this.fields) {
				if (this.record) {
					values[f.machineName] = this.record.values[f.machineName] ?? null
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
		onInput(field, value) {
			this.values = { ...this.values, [field.machineName]: value }
			if (this.serverErrors[field.machineName]) {
				const next = { ...this.serverErrors }
				delete next[field.machineName]
				this.serverErrors = next
			}
		},
		async save() {
			// Apply computed values, then block on client-side errors.
			const payload = { ...this.evaluation.values }
			if (Object.keys(this.evaluation.errors).length > 0) {
				showError(t('dataforms', 'Please fix the highlighted fields'))
				return
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

.err {
	color: var(--color-error);
	font-size: 0.82em;
	margin: 4px 0 0;
}
</style>
