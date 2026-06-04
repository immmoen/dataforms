<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="form-builder">
		<div class="header">
			<div>
				<h3>{{ t('dataforms', 'Forms') }}</h3>
				<p class="hint">
					{{ t('dataforms', 'Build one or more data-entry forms: choose which fields appear, in what order, grouped into sections. Records can be added with any form.') }}
				</p>
			</div>
			<NcButton v-if="canManage" type="primary" :disabled="fields.length === 0" @click="openAdd">
				<template #icon><PlusIcon :size="20" /></template>
				{{ t('dataforms', 'Add form') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" class="centered" :size="32" />

		<NcEmptyContent
			v-else-if="fields.length === 0"
			:name="t('dataforms', 'Add fields first')"
			:description="t('dataforms', 'Forms arrange a register\'s fields, so define the schema before building forms.')">
			<template #icon><FormIcon :size="20" /></template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="forms.length === 0"
			:name="t('dataforms', 'No forms yet')"
			:description="t('dataforms', 'Without a form, the New record button shows every field. Add a form to present a tailored subset in sections.')">
			<template #icon><FormIcon :size="20" /></template>
		</NcEmptyContent>

		<ul v-else class="form-list">
			<li v-for="form in forms" :key="form.id" class="form-row">
				<span class="form-title">{{ form.title }}</span>
				<span class="form-meta">
					{{ n('dataforms', '%n section', '%n sections', form.definition.sections.length) }}
				</span>
				<span class="spacer" />
				<NcActions v-if="canManage">
					<NcActionButton @click="openEdit(form)">
						<template #icon><PencilIcon :size="20" /></template>
						{{ t('dataforms', 'Edit') }}
					</NcActionButton>
					<NcActionButton @click="remove(form)">
						<template #icon><DeleteIcon :size="20" /></template>
						{{ t('dataforms', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>

		<NcDialog
			v-if="showDialog"
			:name="editing ? t('dataforms', 'Edit form') : t('dataforms', 'Add form')"
			size="large"
			:can-close="!saving"
			@closing="showDialog = false">
			<div class="form-form">
				<NcTextField v-model="draft.title" :label="t('dataforms', 'Form name')" :required="true" />

				<div v-for="(section, si) in draft.sections" :key="si" class="section-block">
					<div class="section-head">
						<NcTextField v-model="section.title" :label="t('dataforms', 'Section title (optional)')" class="section-title-input" />
						<NcButton type="tertiary" :aria-label="t('dataforms', 'Remove section')" @click="draft.sections.splice(si, 1)">
							<template #icon><DeleteIcon :size="18" /></template>
						</NcButton>
					</div>
					<label class="block-label">{{ t('dataforms', 'Fields in this section') }}</label>
					<NcSelect
						v-model="section.fields"
						:options="fieldMachineNames"
						:multiple="true"
						:close-on-select="false"
						:placeholder="t('dataforms', 'Pick fields…')"
						class="section-fields" />
				</div>

				<NcButton type="tertiary" @click="addSection">
					<template #icon><PlusIcon :size="18" /></template>
					{{ t('dataforms', 'Add section') }}
				</NcButton>
			</div>

			<template #actions>
				<NcButton :disabled="saving" @click="showDialog = false">{{ t('dataforms', 'Cancel') }}</NcButton>
				<NcButton type="primary" :disabled="saving || draft.title.trim() === ''" @click="submit">
					{{ editing ? t('dataforms', 'Save') : t('dataforms', 'Add form') }}
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
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FormIcon from 'vue-material-design-icons/FormSelect.vue'

import { listForms, createForm, updateForm, deleteForm } from '../api/forms.js'
import { listFields } from '../api/fields.js'

export default {
	name: 'FormBuilder',
	components: {
		NcActions, NcActionButton, NcButton, NcDialog, NcEmptyContent, NcLoadingIcon,
		NcSelect, NcTextField, PlusIcon, PencilIcon, DeleteIcon, FormIcon,
	},
	props: {
		registerId: { type: Number, required: true },
		canManage: { type: Boolean, default: false },
	},
	data() {
		return {
			forms: [],
			fields: [],
			loading: true,
			showDialog: false,
			editing: null,
			saving: false,
			draft: { title: '', sections: [] },
		}
	},
	computed: {
		fieldMachineNames() {
			return this.fields.map((f) => f.machineName)
		},
		fieldLabel() {
			const map = {}
			for (const f of this.fields) {
				map[f.machineName] = f.label
			}
			return map
		},
	},
	watch: {
		registerId() { this.load() },
	},
	mounted() { this.load() },
	methods: {
		t,
		n,
		async load() {
			this.loading = true
			try {
				this.fields = await listFields(this.registerId)
				this.forms = await listForms(this.registerId)
			} catch (e) {
				showError(t('dataforms', 'Could not load forms'))
				console.error(e)
			} finally {
				this.loading = false
			}
		},
		openAdd() {
			this.editing = null
			this.draft = { title: '', sections: [{ title: '', fields: [] }] }
			this.showDialog = true
		},
		openEdit(form) {
			this.editing = form
			this.draft = {
				title: form.title,
				sections: (form.definition.sections.length ? form.definition.sections : [{ title: '', fields: [] }])
					.map((s) => ({ title: s.title || '', fields: [...(s.fields || [])] })),
			}
			this.showDialog = true
		},
		addSection() {
			this.draft.sections.push({ title: '', fields: [] })
		},
		payload() {
			return {
				title: this.draft.title.trim(),
				definition: {
					sections: this.draft.sections.map((s) => ({
						title: (s.title || '').trim(),
						fields: [...(s.fields || [])],
					})),
				},
			}
		},
		async submit() {
			if (this.draft.title.trim() === '' || this.saving) {
				return
			}
			this.saving = true
			try {
				if (this.editing) {
					const updated = await updateForm(this.editing.id, this.payload())
					const i = this.forms.findIndex((f) => f.id === updated.id)
					if (i !== -1) this.forms.splice(i, 1, updated)
				} else {
					this.forms.push(await createForm(this.registerId, this.payload()))
				}
				this.showDialog = false
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not save the form'))
				console.error(e)
			} finally {
				this.saving = false
			}
		},
		async remove(form) {
			if (!window.confirm(t('dataforms', 'Delete form "{title}"?', { title: form.title }))) {
				return
			}
			try {
				await deleteForm(form.id)
				this.forms = this.forms.filter((f) => f.id !== form.id)
			} catch (e) {
				showError(t('dataforms', 'Could not delete the form'))
				console.error(e)
			}
		},
	},
}
</script>

<style scoped>
.form-builder { max-width: 820px; margin: 0 auto; padding: 24px; }
.header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.header h3 { margin: 0; }
.hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 2px 0 0; max-width: 560px; }
.centered { margin: 60px auto; }
.form-list { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); overflow: hidden; }
.form-row { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-bottom: 1px solid var(--color-border); }
.form-row:last-child { border-bottom: none; }
.form-title { font-weight: 600; }
.form-meta { color: var(--color-text-maxcontrast); font-size: 0.85em; }
.spacer { flex: 1; }
.form-form { display: flex; flex-direction: column; gap: 16px; min-width: min(560px, 84vw); padding: 8px 2px; }
.section-block { border-left: 3px solid var(--color-primary-element); padding-left: 14px; display: flex; flex-direction: column; gap: 8px; }
.section-head { display: flex; align-items: flex-end; gap: 8px; }
.section-title-input { flex: 1; }
.block-label { font-weight: 600; font-size: 0.85em; }
</style>
