<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Renders the right input widget for a field type and emits its value. -->
<template>
	<div class="field-input">
		<NcTextField
			v-if="['text', 'email', 'url', 'phone'].includes(field.type)"
			:model-value="String(modelValue ?? '')"
			:type="htmlType"
			:disabled="disabled"
			:label="label"
			@update:model-value="emit($event)" />

		<NcTextArea
			v-else-if="field.type === 'longtext'"
			:model-value="String(modelValue ?? '')"
			:disabled="disabled"
			:label="label"
			@update:model-value="emit($event)" />

		<NcTextField
			v-else-if="['number', 'currency', 'percentage'].includes(field.type)"
			:model-value="String(modelValue ?? '')"
			type="number"
			:disabled="disabled"
			:label="label"
			@update:model-value="emit($event === '' ? null : Number($event))" />

		<NcCheckboxRadioSwitch
			v-else-if="field.type === 'boolean'"
			:model-value="!!modelValue"
			:disabled="disabled"
			@update:model-value="emit($event)">
			{{ label }}
		</NcCheckboxRadioSwitch>

		<div v-else-if="['date', 'datetime', 'time'].includes(field.type)" class="native">
			<label class="native-label">{{ label }}</label>
			<input
				:type="htmlType"
				:value="modelValue ?? ''"
				:disabled="disabled"
				class="native-input"
				@input="emit($event.target.value)">
		</div>

		<div v-else-if="field.type === 'select'" class="native">
			<label class="native-label">{{ label }}</label>
			<NcSelect
				:model-value="modelValue"
				:options="options"
				:disabled="disabled"
				:clearable="true"
				:input-label="label"
				@update:model-value="emit($event)" />
		</div>

		<div v-else-if="field.type === 'multiselect'" class="native">
			<label class="native-label">{{ label }}</label>
			<NcSelect
				:model-value="Array.isArray(modelValue) ? modelValue : []"
				:options="options"
				:multiple="true"
				:disabled="disabled"
				:input-label="label"
				@update:model-value="emit($event)" />
		</div>

		<div v-else-if="field.type === 'relation'" class="native">
			<label class="native-label">{{ label }}</label>
			<NcSelect
				:model-value="modelValue"
				:options="relationOptions"
				label="label"
				:clearable="true"
				:loading="relLoading"
				:disabled="disabled"
				:input-label="label"
				@search="onRelSearch"
				@update:model-value="emit($event)" />
		</div>

		<div v-else-if="field.type === 'file'" class="native">
			<label class="native-label">{{ label }}</label>
			<div class="file-row">
				<a v-if="modelValue && modelValue.id" :href="fileUrl(modelValue.id)" target="_blank" rel="noopener noreferrer" class="file-link">
					📎 {{ modelValue.name }}
				</a>
				<span v-else class="no-file">{{ t('dataforms', 'No file') }}</span>
				<NcButton v-if="!disabled" @click="pickFile">
					{{ modelValue ? t('dataforms', 'Change') : t('dataforms', 'Choose from Files') }}
				</NcButton>
				<NcButton v-if="modelValue && !disabled" type="tertiary" @click="emit(null)">
					{{ t('dataforms', 'Remove') }}
				</NcButton>
			</div>
		</div>

		<NcTextField
			v-else
			:model-value="String(modelValue ?? '')"
			:disabled="disabled"
			:label="label + (['user', 'group'].includes(field.type) ? ' (id)' : '')"
			@update:model-value="emit($event)" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { listOptions, resolveFile } from '../api/records.js'

export default {
	name: 'FieldInput',
	components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField },
	props: {
		field: { type: Object, required: true },
		modelValue: { type: [String, Number, Boolean, Array, Object, null], default: null },
		label: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
	data() {
		return {
			relationOptions: [],
			relLoading: false,
			relTimer: null,
		}
	},
	mounted() {
		if (this.field.type === 'relation') {
			// Seed with the current value so it displays, then load choices.
			if (this.modelValue && typeof this.modelValue === 'object') {
				this.relationOptions = [this.modelValue]
			}
			this.loadRelations('')
		}
	},
	computed: {
		htmlType() {
			return {
				email: 'email', url: 'url', phone: 'tel',
				date: 'date', datetime: 'datetime-local', time: 'time',
			}[this.field.type] ?? 'text'
		},
		options() {
			return this.field.config?.options ?? []
		},
	},
	methods: {
		t,
		emit(value) {
			this.$emit('update:modelValue', value)
		},
		fileUrl(id) {
			return generateUrl('/f/{id}', { id })
		},
		async pickFile() {
			try {
				const picker = getFilePickerBuilder(t('dataforms', 'Choose a file'))
					.allowDirectories(false)
					.setMultiSelect(false)
					.build()
				const path = await picker.pick()
				if (!path) {
					return
				}
				const file = await resolveFile(path)
				this.emit(file)
			} catch (e) {
				if (e) { // pick() rejects on cancel
					showError(t('dataforms', 'Could not attach the file'))
					console.error(e)
				}
			}
		},
		onRelSearch(query) {
			clearTimeout(this.relTimer)
			this.relTimer = setTimeout(() => this.loadRelations(query), 250)
		},
		async loadRelations(search) {
			const target = this.field.config?.targetRegisterId
			if (!target) {
				return
			}
			this.relLoading = true
			try {
				const opts = await listOptions(target, { display: this.field.config?.displayField ?? '', search })
				// Keep the current value present so it stays displayed.
				const current = this.modelValue && typeof this.modelValue === 'object' ? [this.modelValue] : []
				const seen = new Set(opts.map((o) => o.id))
				this.relationOptions = [...opts, ...current.filter((c) => !seen.has(c.id))]
			} catch (e) {
				console.error(e)
			} finally {
				this.relLoading = false
			}
		},
	},
}
</script>

<style scoped>
.field-input {
	width: 100%;
}

.native-label {
	display: block;
	font-weight: 600;
	font-size: 0.9em;
	margin-bottom: 4px;
}

.native-input {
	width: 100%;
	min-height: 40px;
	padding: 6px 10px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.file-row {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.file-link {
	font-weight: 500;
}

.no-file {
	color: var(--color-text-maxcontrast);
}
</style>
