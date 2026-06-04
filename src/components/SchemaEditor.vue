<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="schema-editor">
		<div class="header">
			<div>
				<h3>{{ t('dataforms', 'Fields') }}</h3>
				<p class="hint">
					{{ t('dataforms', 'The typed fields this register stores. Machine names are generated once and never change.') }}
				</p>
			</div>
			<NcButton v-if="canManage" type="primary" @click="openAdd">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dataforms', 'Add field') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" class="centered" :size="32" />

		<NcEmptyContent
			v-else-if="fields.length === 0"
			:name="t('dataforms', 'No fields yet')"
			:description="t('dataforms', 'Add the first field to define what this register stores.')">
			<template #icon>
				<TableColumnIcon :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="field-list">
			<li v-for="(field, index) in fields" :key="field.id" class="field-row">
				<span class="type-badge">{{ typeLabel(field.type) }}</span>
				<span class="field-label">{{ field.label }}</span>
				<code class="machine-name">{{ field.machineName }}</code>
				<span v-if="field.mandatory" class="req">{{ t('dataforms', 'required') }}</span>
				<span v-if="field.unique" class="flag">{{ t('dataforms', 'unique') }}</span>
				<span v-if="optionCount(field)" class="flag">
					{{ n('dataforms', '%n option', '%n options', optionCount(field)) }}
				</span>
				<span class="spacer" />
				<template v-if="canManage">
					<NcButton type="tertiary-no-background" :disabled="index === 0" :aria-label="t('dataforms', 'Move up')" @click="move(index, -1)">
						<template #icon><ChevronUpIcon :size="20" /></template>
					</NcButton>
					<NcButton type="tertiary-no-background" :disabled="index === fields.length - 1" :aria-label="t('dataforms', 'Move down')" @click="move(index, 1)">
						<template #icon><ChevronDownIcon :size="20" /></template>
					</NcButton>
					<NcActions>
						<NcActionButton @click="openEdit(field)">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('dataforms', 'Edit') }}
						</NcActionButton>
						<NcActionButton @click="remove(field)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('dataforms', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</template>
			</li>
		</ul>

		<!-- Add field dialog -->
		<NcDialog
			v-if="showAdd"
			:name="editingField ? t('dataforms', 'Edit field') : t('dataforms', 'Add field')"
			size="normal"
			:can-close="!saving"
			@closing="showAdd = false">
			<div class="add-form">
				<NcTextField
					v-model="draft.label"
					:label="t('dataforms', 'Label')"
					:required="true" />

				<div class="field-block">
					<label class="block-label">{{ t('dataforms', 'Type') }}</label>
					<NcSelect
						v-model="draft.type"
						:options="typeOptions"
						:reduce="(o) => o.id"
						label="label"
						:clearable="false"
						:disabled="!!editingField" />
					<p v-if="editingField" class="block-hint">
						{{ t('dataforms', 'Type and machine name “{name}” cannot change.', { name: editingField.machineName }) }}
					</p>
				</div>

				<div v-if="needsOptions" class="field-block">
					<label class="block-label">{{ t('dataforms', 'Options (one per line)') }}</label>
					<NcTextArea
						v-model="draft.optionsText"
						:placeholder="t('dataforms', 'Consent\nContract\nLegal obligation')" />
				</div>

				<div v-if="needsNumberConfig" class="number-config">
					<NcTextField v-model="draft.min" type="number" :label="t('dataforms', 'Min')" />
					<NcTextField v-model="draft.max" type="number" :label="t('dataforms', 'Max')" />
					<NcTextField v-model="draft.decimals" type="number" :label="t('dataforms', 'Decimals')" />
				</div>

				<div v-if="draft.type === 'computed'" class="field-block">
					<NcTextField v-model="draft.expression" :label="t('dataforms', 'Expression')" :placeholder="t('dataforms', 'e.g. likelihood * impact')" />
					<p class="block-hint">
						{{ t('dataforms', 'Computed read-only from other fields on save. Functions: sum, round, if, concat, min, max, abs, len, lower, upper.') }}
						<br>{{ t('dataforms', 'Fields:') }} <code>{{ machineNames }}</code>
					</p>
				</div>

				<div v-if="draft.type === 'auto'" class="field-block">
					<label class="block-label">{{ t('dataforms', 'Records') }}</label>
					<NcSelect v-model="draft.autoKind" :options="autoKinds" :reduce="(o) => o.id" label="label" :clearable="false" />
					<p class="block-hint">{{ t('dataforms', 'Filled automatically; read-only in the form.') }}</p>
				</div>

				<div v-if="draft.type === 'relation'" class="field-block">
					<label class="block-label">{{ t('dataforms', 'Linked register') }}</label>
					<NcSelect
						v-model="draft.target"
						:options="registerOptions"
						:reduce="(o) => o.id"
						label="label"
						:clearable="false"
						:placeholder="t('dataforms', 'Pick a register to link to')" />
					<label class="block-label" style="margin-top:12px">{{ t('dataforms', 'Display field') }}</label>
					<NcSelect
						v-model="draft.displayField"
						:options="targetFieldOptions"
						:reduce="(o) => o.id"
						label="label"
						:clearable="true"
						:placeholder="t('dataforms', 'Which field to show (defaults to the first)')" />
					<NcCheckboxRadioSwitch v-model="draft.multiple" style="margin-top:12px">
						{{ t('dataforms', 'Allow linking several records') }}
					</NcCheckboxRadioSwitch>
					<label class="block-label" style="margin-top:12px">{{ t('dataforms', 'When a linked record is deleted') }}</label>
					<NcSelect
						v-model="draft.onDelete"
						:options="onDeleteOptions"
						:reduce="(o) => o.id"
						label="label"
						:clearable="false" />
				</div>

				<NcTextField
					v-model="draft.help"
					:label="t('dataforms', 'Help text (optional)')"
					:placeholder="t('dataforms', 'Shown under the field in the form')" />

				<NcTextField
					v-if="!['boolean', 'file', 'relation', 'multiselect', 'computed', 'auto'].includes(draft.type)"
					v-model="draft.default"
					:label="t('dataforms', 'Default value (optional)')" />

				<NcCheckboxRadioSwitch v-model="draft.mandatory">
					{{ t('dataforms', 'Required') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="draft.unique">
					{{ t('dataforms', 'Unique values') }}
				</NcCheckboxRadioSwitch>
			</div>

			<template #actions>
				<NcButton :disabled="saving" @click="showAdd = false">
					{{ t('dataforms', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || draft.label.trim() === '' || (draft.type === 'relation' && !draft.target)"
					@click="submit">
					{{ editingField ? t('dataforms', 'Save') : t('dataforms', 'Add field') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import TableColumnIcon from 'vue-material-design-icons/TableColumn.vue'

import { listFields, createField, updateField, deleteField, reorderFields, FIELD_TYPES, AUTO_KINDS, typeLabel } from '../api/fields.js'
import { listRegisters } from '../api/registers.js'

const emptyDraft = () => ({
	label: '',
	type: 'text',
	optionsText: '',
	min: '',
	max: '',
	decimals: '',
	target: null,
	displayField: null,
	multiple: false,
	onDelete: 'null',
	expression: '',
	autoKind: 'created_at',
	help: '',
	default: '',
	mandatory: false,
	unique: false,
})

export default {
	name: 'SchemaEditor',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
		PlusIcon,
		PencilIcon,
		DeleteIcon,
		ChevronUpIcon,
		ChevronDownIcon,
		TableColumnIcon,
	},
	props: {
		registerId: {
			type: Number,
			required: true,
		},
		canManage: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			fields: [],
			loading: true,
			showAdd: false,
			editingField: null,
			saving: false,
			draft: emptyDraft(),
			typeOptions: FIELD_TYPES,
			registers: [],
			targetFields: [],
		}
	},
	computed: {
		needsOptions() {
			return this.draft.type === 'select' || this.draft.type === 'multiselect'
		},
		needsNumberConfig() {
			return ['number', 'currency', 'percentage'].includes(this.draft.type)
		},
		machineNames() {
			return this.fields.map((f) => f.machineName).join(', ')
		},
		autoKinds() {
			return AUTO_KINDS
		},
		registerOptions() {
			return this.registers
				.filter((r) => r.id !== this.registerId)
				.map((r) => ({ id: r.id, label: r.title }))
		},
		targetFieldOptions() {
			return this.targetFields.map((f) => ({ id: f.machineName, label: f.label }))
		},
		onDeleteOptions() {
			return [
				{ id: 'null', label: t('dataforms', 'Clear the link (keep this record)') },
				{ id: 'block', label: t('dataforms', 'Prevent the deletion') },
				{ id: 'cascade', label: t('dataforms', 'Also delete this record') },
			]
		},
	},
	watch: {
		registerId() {
			this.load()
		},
		'draft.target'(target) {
			this.targetFields = []
			this.draft.displayField = null
			if (target) {
				listFields(target).then((f) => { this.targetFields = f }).catch(() => {})
			}
		},
	},
	mounted() {
		this.load()
		listRegisters().then((r) => { this.registers = r }).catch(() => {})
	},
	methods: {
		t,
		n,
		typeLabel,
		optionCount(field) {
			return field.config?.options?.length ?? 0
		},
		async load() {
			this.loading = true
			try {
				this.fields = await listFields(this.registerId)
			} catch (e) {
				showError(t('dataforms', 'Could not load fields'))
				console.error(e)
			} finally {
				this.loading = false
			}
		},
		openAdd() {
			this.editingField = null
			this.draft = emptyDraft()
			this.showAdd = true
		},
		openEdit(field) {
			this.editingField = field
			const cfg = field.config ?? {}
			this.draft = {
				label: field.label,
				type: field.type,
				optionsText: (cfg.options ?? []).join('\n'),
				min: cfg.min ?? '',
				max: cfg.max ?? '',
				decimals: cfg.decimals ?? '',
				target: cfg.targetRegisterId ?? null,
				displayField: cfg.displayField ?? null,
				multiple: cfg.multiple ?? false,
				onDelete: cfg.onDelete ?? 'null',
				expression: cfg.expression ?? '',
				autoKind: cfg.kind ?? 'created_at',
				help: cfg.help ?? '',
				default: field.default ?? '',
				mandatory: field.mandatory,
				unique: field.unique,
			}
			this.showAdd = true
		},
		submit() {
			return this.editingField ? this.submitEdit() : this.submitAdd()
		},
		buildConfig() {
			const config = {}
			if (this.needsOptions) {
				config.options = this.draft.optionsText
					.split('\n')
					.map((s) => s.trim())
					.filter((s) => s !== '')
			}
			if (this.needsNumberConfig) {
				if (this.draft.min !== '') config.min = Number(this.draft.min)
				if (this.draft.max !== '') config.max = Number(this.draft.max)
				if (this.draft.decimals !== '') config.decimals = Number(this.draft.decimals)
			}
			if (this.draft.type === 'relation') {
				config.targetRegisterId = this.draft.target
				config.displayField = this.draft.displayField ?? ''
				config.multiple = this.draft.multiple
				config.onDelete = this.draft.onDelete
			}
			if (this.draft.type === 'computed') {
				config.expression = this.draft.expression
			}
			if (this.draft.type === 'auto') {
				config.kind = this.draft.autoKind
			}
			if (this.draft.help && this.draft.help.trim() !== '') {
				config.help = this.draft.help.trim()
			}
			return config
		},
		async submitAdd() {
			if (this.draft.label.trim() === '' || this.saving) {
				return
			}
			this.saving = true
			try {
				const field = await createField(this.registerId, {
					label: this.draft.label.trim(),
					type: this.draft.type,
					config: this.buildConfig(),
					mandatory: this.draft.mandatory,
					unique: this.draft.unique,
					default: this.draft.default ?? '',
				})
				this.fields.push(field)
				this.showAdd = false
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not add the field'))
				console.error(e)
			} finally {
				this.saving = false
			}
		},
		async submitEdit() {
			if (this.draft.label.trim() === '' || this.saving) {
				return
			}
			this.saving = true
			try {
				const updated = await updateField(this.editingField.id, {
					label: this.draft.label.trim(),
					config: this.buildConfig(),
					mandatory: this.draft.mandatory,
					unique: this.draft.unique,
					default: this.draft.default ?? '',
				})
				const i = this.fields.findIndex((f) => f.id === updated.id)
				if (i !== -1) this.fields.splice(i, 1, updated)
				this.showAdd = false
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not save the field'))
				console.error(e)
			} finally {
				this.saving = false
			}
		},
		async move(index, delta) {
			const target = index + delta
			if (target < 0 || target >= this.fields.length) {
				return
			}
			const reordered = [...this.fields]
			const [moved] = reordered.splice(index, 1)
			reordered.splice(target, 0, moved)
			this.fields = reordered // optimistic
			try {
				this.fields = await reorderFields(this.registerId, reordered.map((f) => f.id))
			} catch (e) {
				showError(t('dataforms', 'Could not reorder fields'))
				this.load()
				console.error(e)
			}
		},
		async remove(field) {
			if (!window.confirm(t('dataforms', 'Delete field "{label}"?', { label: field.label }))) {
				return
			}
			try {
				await deleteField(field.id)
				this.fields = this.fields.filter((f) => f.id !== field.id)
			} catch (e) {
				showError(t('dataforms', 'Could not delete the field'))
				console.error(e)
			}
		},
	},
}
</script>

<style scoped>
.schema-editor {
	max-width: 820px;
	margin: 0 auto;
	padding: 24px;
}

.header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 16px;
}

.header h3 {
	margin: 0;
}

.hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 2px 0 0;
}

.centered {
	margin: 60px auto;
}

.field-list {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}

.field-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	border-bottom: 1px solid var(--color-border);
}

.field-row:last-child {
	border-bottom: none;
}

.type-badge {
	font-size: 0.78em;
	font-weight: 600;
	padding: 2px 9px;
	border-radius: 16px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.field-label {
	font-weight: 600;
}

.machine-name {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.req {
	color: var(--color-error);
	font-size: 0.8em;
	font-weight: 600;
}

.flag {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.spacer {
	flex: 1;
}

.add-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: min(460px, 80vw);
	padding: 8px 0;
}

.block-label {
	display: block;
	font-weight: 600;
	font-size: 0.9em;
	margin-bottom: 4px;
}

.block-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	margin: 6px 0 0;
}

.number-config {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 12px;
}
</style>
