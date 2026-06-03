<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!--
	Renders the input control for a field. The VISIBLE label is rendered once by
	the parent (RecordForm); these controls carry only an accessible aria-label
	(except the boolean toggle, whose label is its visible text), so a field's
	label is never shown more than once.
-->
<template>
	<div class="field-input">
		<input
			v-if="['text', 'email', 'url', 'phone'].includes(field.type)"
			:type="htmlType"
			:value="modelValue ?? ''"
			:disabled="disabled"
			:aria-label="label"
			class="native-input"
			@input="emit($event.target.value)">

		<textarea
			v-else-if="field.type === 'longtext'"
			:value="modelValue ?? ''"
			:disabled="disabled"
			:aria-label="label"
			rows="3"
			class="native-input native-textarea"
			@input="emit($event.target.value)" />

		<input
			v-else-if="['number', 'currency', 'percentage'].includes(field.type)"
			type="number"
			:value="modelValue ?? ''"
			:disabled="disabled"
			:aria-label="label"
			class="native-input"
			@input="emit($event.target.value === '' ? null : Number($event.target.value))">

		<div v-else-if="field.type === 'boolean'" class="bool-group">
			<NcCheckboxRadioSwitch
				:model-value="boolChoice"
				value="yes"
				:name="radioName"
				type="radio"
				:disabled="disabled"
				@update:model-value="emit(true)">
				{{ t('dataforms', 'Yes') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				:model-value="boolChoice"
				value="no"
				:name="radioName"
				type="radio"
				:disabled="disabled"
				@update:model-value="emit(false)">
				{{ t('dataforms', 'No') }}
			</NcCheckboxRadioSwitch>
		</div>

		<input
			v-else-if="['date', 'datetime', 'time'].includes(field.type)"
			:type="htmlType"
			:value="modelValue ?? ''"
			:disabled="disabled"
			:aria-label="label"
			class="native-input"
			@input="emit($event.target.value)">

		<NcSelect
			v-else-if="field.type === 'select'"
			:model-value="modelValue"
			:options="options"
			:disabled="disabled"
			:clearable="true"
			:aria-label="label"
			:placeholder="t('dataforms', 'Choose…')"
			@update:model-value="emit($event)" />

		<NcSelect
			v-else-if="field.type === 'multiselect'"
			:model-value="Array.isArray(modelValue) ? modelValue : []"
			:options="options"
			:multiple="true"
			:disabled="disabled"
			:aria-label="label"
			:placeholder="t('dataforms', 'Choose…')"
			@update:model-value="emit($event)" />

		<NcSelect
			v-else-if="field.type === 'relation'"
			:model-value="modelValue"
			:options="relationOptions"
			label="label"
			:clearable="true"
			:loading="relLoading"
			:disabled="disabled"
			:aria-label="label"
			:placeholder="t('dataforms', 'Choose a record…')"
			@search="onRelSearch"
			@update:model-value="emit($event)" />

		<div v-else-if="field.type === 'file'" class="file-field">
			<div class="file-row">
				<a v-if="modelValue && modelValue.id" :href="fileUrl(modelValue.id)" target="_blank" rel="noopener noreferrer" class="file-link">
					📎 {{ modelValue.name }}
				</a>
				<span v-else class="no-file">{{ t('dataforms', 'No file attached') }}</span>
			</div>
			<div v-if="!disabled" class="file-row">
				<NcButton @click.prevent="triggerUpload">
					<template #icon><UploadIcon :size="18" /></template>
					{{ t('dataforms', 'Upload from computer') }}
				</NcButton>
				<NcButton type="tertiary" @click.prevent="pickFile">
					<template #icon><FolderIcon :size="18" /></template>
					{{ t('dataforms', 'Choose from Files') }}
				</NcButton>
				<NcButton v-if="modelValue" type="tertiary" @click.prevent="emit(null)">
					{{ t('dataforms', 'Remove') }}
				</NcButton>
				<NcLoadingIcon v-if="uploading" :size="20" />
				<input ref="fileInput" type="file" class="hidden-input" @change="onLocalFile">
			</div>
		</div>

		<input
			v-else
			type="text"
			:value="modelValue ?? ''"
			:disabled="disabled"
			:aria-label="label"
			:placeholder="['user', 'group'].includes(field.type) ? t('dataforms', 'Enter an id') : ''"
			class="native-input"
			@input="emit($event.target.value)">
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import UploadIcon from 'vue-material-design-icons/Upload.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'

import { listOptions, resolveFile, uploadLocalFile } from '../api/records.js'

export default {
	name: 'FieldInput',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, UploadIcon, FolderIcon },
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
			uploading: false,
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
		boolChoice() {
			if (this.modelValue === true) {
				return 'yes'
			}
			if (this.modelValue === false) {
				return 'no'
			}
			return ''
		},
		radioName() {
			return 'df-bool-' + (this.field.machineName ?? this.field.id)
		},
	},
	mounted() {
		if (this.field.type === 'relation') {
			if (this.modelValue && typeof this.modelValue === 'object') {
				this.relationOptions = [this.modelValue]
			}
			this.loadRelations('')
		}
	},
	methods: {
		t,
		emit(value) {
			this.$emit('update:modelValue', value)
		},
		fileUrl(id) {
			return generateUrl('/f/{id}', { id })
		},
		triggerUpload() {
			this.$refs.fileInput?.click()
		},
		async onLocalFile(event) {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) {
				return
			}
			this.uploading = true
			try {
				const attached = await uploadLocalFile(file)
				this.emit(attached)
			} catch (e) {
				showError(t('dataforms', 'Could not upload the file'))
				console.error(e)
			} finally {
				this.uploading = false
			}
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
				if (e) {
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

.native-input {
	width: 100%;
	min-height: 40px;
	padding: 7px 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: inherit;
	font-family: inherit;
}

.native-input:focus,
.native-input:focus-visible {
	border-color: var(--color-primary-element);
	outline: none;
}

.native-input:disabled {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.native-textarea {
	resize: vertical;
	min-height: 72px;
}

.bool-group {
	display: flex;
	gap: 18px;
	align-items: center;
}

.file-field {
	display: flex;
	flex-direction: column;
	gap: 8px;
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

.hidden-input {
	display: none;
}
</style>
