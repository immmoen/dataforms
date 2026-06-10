<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!--
	Renders the input control for a field. The VISIBLE label is rendered once by
	the parent (RecordForm); these controls carry only an accessible aria-label
	(except the boolean toggle, whose label is its visible text), so a field's
	label is never shown more than once.
-->
<template>
	<div class="field-input">
		<input v-if="['text', 'email', 'url', 'phone'].includes(field.type)"
			:type="htmlType"
			:value="modelValue ?? ''"
			:disabled="disabled"
			v-bind="ariaAttrs"
			class="native-input"
			@input="emit($event.target.value)">

		<textarea v-else-if="field.type === 'longtext'"
			:value="modelValue ?? ''"
			:disabled="disabled"
			v-bind="ariaAttrs"
			rows="3"
			class="native-input native-textarea"
			@input="emit($event.target.value)" />

		<input v-else-if="['number', 'currency', 'percentage'].includes(field.type)"
			type="number"
			:value="modelValue ?? ''"
			:disabled="disabled"
			v-bind="ariaAttrs"
			class="native-input"
			@input="emit($event.target.value === '' ? null : Number($event.target.value))">

		<div v-else-if="field.type === 'boolean'" class="bool-group">
			<NcCheckboxRadioSwitch :model-value="boolChoice"
				value="yes"
				:name="radioName"
				type="radio"
				:disabled="disabled"
				@update:model-value="emit(true)">
				{{ t('dataforms', 'Yes') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch :model-value="boolChoice"
				value="no"
				:name="radioName"
				type="radio"
				:disabled="disabled"
				@update:model-value="emit(false)">
				{{ t('dataforms', 'No') }}
			</NcCheckboxRadioSwitch>
		</div>

		<input v-else-if="['date', 'datetime', 'time'].includes(field.type)"
			:type="htmlType"
			:value="modelValue ?? ''"
			:disabled="disabled"
			v-bind="ariaAttrs"
			class="native-input"
			@input="emit($event.target.value)">

		<NcSelect v-else-if="field.type === 'select'"
			:model-value="modelValue"
			:options="options"
			:disabled="disabled"
			:clearable="true"
			v-bind="ariaAttrs"
			:placeholder="t('dataforms', 'Choose…')"
			@update:model-value="emit($event)" />

		<GroupedMultiSelect v-else-if="field.type === 'multiselect' && field.config?.groupPattern"
			:model-value="Array.isArray(modelValue) ? modelValue : []"
			:options="options"
			:group-pattern="field.config.groupPattern"
			:label="label"
			:disabled="disabled"
			@update:model-value="emit($event)" />

		<NcSelect v-else-if="field.type === 'multiselect'"
			:model-value="Array.isArray(modelValue) ? modelValue : []"
			:options="options"
			:multiple="true"
			:disabled="disabled"
			v-bind="ariaAttrs"
			:placeholder="t('dataforms', 'Choose…')"
			@update:model-value="emit($event)" />

		<NcSelect v-else-if="field.type === 'relation'"
			:model-value="relationModel"
			:options="relationOptions"
			label="label"
			:multiple="!!field.config?.multiple"
			:clearable="true"
			:close-on-select="!field.config?.multiple"
			:loading="relLoading"
			:disabled="disabled"
			v-bind="ariaAttrs"
			:placeholder="t('dataforms', 'Choose a record…')"
			@search="onRelSearch"
			@update:model-value="emit($event)" />

		<div v-else-if="field.type === 'file'" class="file-field">
			<ul v-if="fileList.length" class="attached-files">
				<li v-for="f in fileList" :key="f.id" class="attached-file">
					<a :href="fileUrl(f.id)"
						target="_blank"
						rel="noopener noreferrer"
						class="file-link"><span aria-hidden="true">📎 </span>{{ f.name }}</a>
					<NcButton v-if="!disabled"
						type="tertiary-no-background"
						:aria-label="t('dataforms', 'Remove file')"
						@click.prevent="removeFile(f.id)">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</li>
			</ul>
			<span v-else class="no-file">{{ t('dataforms', 'No files attached') }}</span>
			<div v-if="!disabled" class="file-row">
				<NcButton @click.prevent="triggerUpload">
					<template #icon>
						<UploadIcon :size="18" />
					</template>
					{{ t('dataforms', 'Add file(s)') }}
				</NcButton>
				<NcLoadingIcon v-if="uploading" :size="20" />
				<input ref="fileInput"
					type="file"
					multiple
					class="hidden-input"
					@change="onLocalFile">
			</div>
		</div>

		<div v-else-if="['computed', 'auto'].includes(field.type)" class="readonly-value">
			<span v-if="modelValue !== null && modelValue !== '' && modelValue !== undefined">{{ modelValue }}</span>
			<span v-else class="placeholder">
				{{ field.type === 'computed' ? t('dataforms', 'Calculated automatically on save') : t('dataforms', 'Set automatically') }}
			</span>
		</div>

		<input v-else
			type="text"
			:value="modelValue ?? ''"
			:disabled="disabled"
			v-bind="ariaAttrs"
			:placeholder="['user', 'group'].includes(field.type) ? t('dataforms', 'Enter an id') : ''"
			class="native-input"
			@input="emit($event.target.value)">
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import UploadIcon from 'vue-material-design-icons/Upload.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

import GroupedMultiSelect from './GroupedMultiSelect.vue'
import { listOptions, uploadLocalFile } from '../api/records.js'

export default {
	name: 'FieldInput',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, UploadIcon, CloseIcon, GroupedMultiSelect },
	props: {
		field: { type: Object, required: true },
		modelValue: { type: [String, Number, Boolean, Array, Object, null], default: null },
		label: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
		required: { type: Boolean, default: false },
		invalid: { type: Boolean, default: false },
		describedby: { type: String, default: '' },
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
		// Accessible-name + state attributes bound onto every input control, so a
		// screen reader announces the field name, its required/invalid state, and
		// any help/error text associated by the parent form (WCAG 4.1.2 / 3.3.1).
		ariaAttrs() {
			return {
				'aria-label': this.label || null,
				'aria-required': this.required || null,
				'aria-invalid': this.invalid || null,
				'aria-describedby': this.describedby || null,
			}
		},
		htmlType() {
			return {
				email: 'email',
				url: 'url',
				phone: 'tel',
				date: 'date',
				datetime: 'datetime-local',
				time: 'time',
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
		fileList() {
			if (Array.isArray(this.modelValue)) {
				return this.modelValue
			}
			// tolerate a legacy single {id,name}
			return this.modelValue && this.modelValue.id ? [this.modelValue] : []
		},
		relationModel() {
			if (this.field.config?.multiple) {
				return Array.isArray(this.modelValue) ? this.modelValue : (this.modelValue ? [this.modelValue] : [])
			}
			return this.modelValue
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
			const files = [...(event.target.files || [])]
			event.target.value = '' // allow re-picking the same file
			if (files.length === 0) {
				return
			}
			this.uploading = true
			try {
				const uploaded = []
				for (const file of files) {
					uploaded.push(await uploadLocalFile(file))
				}
				this.emit([...this.fileList, ...uploaded])
			} catch (e) {
				showError(t('dataforms', 'Could not upload the file(s)'))
				console.error(e)
			} finally {
				this.uploading = false
			}
		},
		removeFile(id) {
			this.emit(this.fileList.filter((f) => f.id !== id))
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

.attached-files {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.attached-file {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 2px 0;
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

.readonly-value {
	min-height: 40px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.readonly-value .placeholder {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
