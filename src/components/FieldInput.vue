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

		<NcTextField
			v-else
			:model-value="String(modelValue ?? '')"
			:disabled="disabled"
			:label="label + (['user', 'group'].includes(field.type) ? ' (id)' : '')"
			@update:model-value="emit($event)" />
	</div>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'FieldInput',
	components: { NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcTextField },
	props: {
		field: { type: Object, required: true },
		modelValue: { type: [String, Number, Boolean, Array, null], default: null },
		label: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
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
		emit(value) {
			this.$emit('update:modelValue', value)
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
</style>
