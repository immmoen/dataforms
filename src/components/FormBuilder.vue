<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!--
	Forms tab. Two modes:
	  • list  — the register's forms, with add/edit/delete.
	  • edit  — a what-you-see-is-what-you-get builder: drag fields from the
	            palette into sections, reorder by dragging, and see a live
	            preview of each control. Uses the native HTML5 drag-and-drop API
	            (no third-party drag library, per the zero-dependency rule).
	The saved shape is { sections: [{ title, fields: [machineName] }] }, which
	the data-entry renderer (RecordForm) consumes directly.
-->
<template>
	<div class="form-builder">
		<!-- ============ LIST MODE ============ -->
		<template v-if="!editing">
			<div class="header">
				<div>
					<h3>{{ t('dataforms', 'Forms') }}</h3>
					<p class="hint">
						{{ t('dataforms', 'A form is a tailored data-entry layout: pick which fields appear, arrange them into sections, and drag them into the order you want. Records can be added with any form.') }}
					</p>
				</div>
				<NcButton v-if="canManage" type="primary" :disabled="fields.length === 0" @click="openAdd">
					<template #icon><PlusIcon :size="20" /></template>
					{{ t('dataforms', 'New form') }}
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
				:description="t('dataforms', 'Without a form, the New record button shows every field. Build a form to present a tailored subset in sections.')">
				<template #icon><FormIcon :size="20" /></template>
				<template #action>
					<NcButton v-if="canManage" type="primary" @click="openAdd">{{ t('dataforms', 'Build your first form') }}</NcButton>
				</template>
			</NcEmptyContent>

			<ul v-else class="form-list">
				<li v-for="form in forms" :key="form.id" class="form-row" @click="canManage && openEdit(form)">
					<FormIcon :size="20" class="row-icon" />
					<span class="form-title">{{ form.title }}</span>
					<span class="form-meta">
						{{ n('dataforms', '%n section', '%n sections', form.definition.sections.length) }}
						· {{ n('dataforms', '%n field', '%n fields', countFields(form)) }}
					</span>
					<span class="spacer" />
					<NcActions v-if="canManage" @click.stop>
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
		</template>

		<!-- ============ EDIT MODE (WYSIWYG builder) ============ -->
		<div v-else class="builder">
			<div class="builder-bar">
				<NcButton type="tertiary" @click="cancelEdit">
					<template #icon><ArrowLeftIcon :size="20" /></template>
					{{ t('dataforms', 'Back') }}
				</NcButton>
				<input
					v-model="draft.title"
					class="title-input"
					:placeholder="t('dataforms', 'Untitled form')"
					:aria-label="t('dataforms', 'Form name')">
				<span class="spacer" />
				<span class="placed-count">{{ n('dataforms', '%n field placed', '%n fields placed', placedCount) }}</span>
				<NcButton type="primary" :disabled="saving || draft.title.trim() === ''" @click="submit">
					<template #icon><ContentSaveIcon :size="20" /></template>
					{{ saving ? t('dataforms', 'Saving…') : t('dataforms', 'Save form') }}
				</NcButton>
			</div>

			<div class="builder-body">
				<!-- palette -->
				<aside
					class="palette"
					:class="{ 'drop-active': dragItem && dragItem.from === 'section' }"
					@dragover.prevent
					@drop.prevent="onDropPalette">
					<h4>{{ t('dataforms', 'Available fields') }}</h4>
					<p class="palette-hint">{{ t('dataforms', 'Drag a field into a section. Drag a field back here to remove it.') }}</p>
					<input
						v-if="fields.length > 8"
						v-model="paletteSearch"
						class="palette-search"
						type="search"
						:placeholder="t('dataforms', 'Search fields…')"
						:aria-label="t('dataforms', 'Search fields')">
					<ul class="palette-list">
						<li
							v-for="f in palette"
							:key="f.machineName"
							class="chip"
							draggable="true"
							@dragstart="startDrag({ from: 'palette', machineName: f.machineName })"
							@dragend="endDrag">
							<DragIcon :size="16" class="grip" />
							<span class="chip-label">{{ f.label }}</span>
							<span class="chip-type">{{ typeLabel(f.type) }}</span>
						</li>
						<li v-if="palette.length === 0" class="palette-empty">
							{{ paletteSearch ? t('dataforms', 'No matching fields') : t('dataforms', 'All fields are placed.') }}
						</li>
					</ul>
				</aside>

				<!-- canvas -->
				<div class="canvas">
					<div
						v-for="(section, si) in draft.sections"
						:key="si"
						class="section"
						:class="{ 'drop-active': dragItem && dragOverSection === si }"
						@dragover.prevent="dragOverSection = si"
						@dragleave="onSectionLeave(si)"
						@drop.prevent="onDropSection(si)">
						<div class="section-head">
							<input
								v-model="section.title"
								class="section-title"
								:placeholder="t('dataforms', 'Section title (optional)')"
								:aria-label="t('dataforms', 'Section title')">
							<div class="section-tools">
								<NcButton type="tertiary-no-background" :aria-label="t('dataforms', 'Move section up')" :disabled="si === 0" @click="moveSection(si, -1)">
									<template #icon><ChevronUpIcon :size="20" /></template>
								</NcButton>
								<NcButton type="tertiary-no-background" :aria-label="t('dataforms', 'Move section down')" :disabled="si === draft.sections.length - 1" @click="moveSection(si, 1)">
									<template #icon><ChevronDownIcon :size="20" /></template>
								</NcButton>
								<NcButton type="tertiary-no-background" :aria-label="t('dataforms', 'Remove section')" @click="removeSection(si)">
									<template #icon><DeleteIcon :size="18" /></template>
								</NcButton>
							</div>
						</div>

						<div v-if="section.fields.length === 0" class="section-empty">
							{{ t('dataforms', 'Drag fields here') }}
						</div>

						<ul v-else class="placed-list">
							<li
								v-for="(mn, idx) in section.fields"
								:key="mn"
								class="placed"
								draggable="true"
								@dragstart="startDrag({ from: 'section', si, machineName: mn })"
								@dragend="endDrag"
								@dragover.prevent.stop="dragOverSection = si"
								@drop.prevent.stop="onDropBefore(si, idx)">
								<DragIcon :size="16" class="grip" />
								<div class="placed-main">
									<div class="placed-label">
										{{ fieldByName[mn] ? fieldByName[mn].label : mn }}
										<span v-if="fieldByName[mn] && fieldByName[mn].mandatory" class="req">*</span>
									</div>
									<!-- WYSIWYG control preview -->
									<div class="preview" :class="'preview-' + previewKind(mn)">
										<template v-if="previewKind(mn) === 'bool'">
											<span class="radio">○ {{ t('dataforms', 'Yes') }}</span>
											<span class="radio">○ {{ t('dataforms', 'No') }}</span>
										</template>
										<template v-else-if="previewKind(mn) === 'select'">
											<span class="faux-select">{{ t('dataforms', 'Choose…') }} ▾</span>
										</template>
										<template v-else-if="previewKind(mn) === 'multiselect'">
											<span class="faux-select">{{ t('dataforms', 'Choose one or more…') }} ▾</span>
										</template>
										<template v-else-if="previewKind(mn) === 'file'">
											<span class="faux-btn">⬆ {{ t('dataforms', 'Add file(s)') }}</span>
										</template>
										<template v-else-if="previewKind(mn) === 'textarea'">
											<span class="faux-area" />
										</template>
										<template v-else-if="previewKind(mn) === 'readonly'">
											<span class="faux-readonly">{{ t('dataforms', 'Set automatically') }}</span>
										</template>
										<template v-else>
											<span class="faux-input">{{ previewPlaceholder(mn) }}</span>
										</template>
									</div>
								</div>
								<button type="button" class="placed-x" :aria-label="t('dataforms', 'Remove field')" @click="removeFromSection(si, mn)">×</button>
							</li>
						</ul>
					</div>

					<NcButton type="secondary" class="add-section" @click="addSection">
						<template #icon><PlusIcon :size="18" /></template>
						{{ t('dataforms', 'Add section') }}
					</NcButton>

					<p v-if="placedCount === 0" class="canvas-hint">
						{{ t('dataforms', 'Tip: a form needs at least one field. Drag fields from the left into a section.') }}
					</p>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FormIcon from 'vue-material-design-icons/FormSelect.vue'
import DragIcon from 'vue-material-design-icons/DragVertical.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'

import { listForms, createForm, updateForm, deleteForm } from '../api/forms.js'
import { listFields, typeLabel } from '../api/fields.js'

export default {
	name: 'FormBuilder',
	components: {
		NcActions, NcActionButton, NcButton, NcEmptyContent, NcLoadingIcon,
		PlusIcon, PencilIcon, DeleteIcon, FormIcon, DragIcon, ArrowLeftIcon, ContentSaveIcon, ChevronUpIcon, ChevronDownIcon,
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
			editing: null,        // null = list mode; a form object or {} = edit mode
			saving: false,
			draft: { title: '', sections: [] },
			paletteSearch: '',
			dragItem: null,       // { from: 'palette'|'section', machineName, si? }
			dragOverSection: null,
		}
	},
	computed: {
		fieldByName() {
			const map = {}
			for (const f of this.fields) {
				map[f.machineName] = f
			}
			return map
		},
		placedNames() {
			const set = new Set()
			for (const s of this.draft.sections) {
				for (const mn of s.fields) {
					set.add(mn)
				}
			}
			return set
		},
		placedCount() {
			return this.placedNames.size
		},
		palette() {
			const q = this.paletteSearch.trim().toLowerCase()
			return this.fields.filter((f) => !this.placedNames.has(f.machineName)
				&& (q === '' || f.label.toLowerCase().includes(q)))
		},
	},
	watch: {
		registerId() { this.editing = null; this.load() },
	},
	mounted() { this.load() },
	methods: {
		t,
		n,
		typeLabel,
		countFields(form) {
			return (form.definition.sections || []).reduce((sum, s) => sum + (s.fields ? s.fields.length : 0), 0)
		},
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
			this.editing = {}
			this.draft = { title: '', sections: [{ title: '', fields: [] }] }
			this.paletteSearch = ''
		},
		openEdit(form) {
			this.editing = form
			this.draft = {
				title: form.title,
				sections: (form.definition.sections.length ? form.definition.sections : [{ title: '', fields: [] }])
					.map((s) => ({ title: s.title || '', fields: [...(s.fields || [])] })),
			}
			this.paletteSearch = ''
		},
		cancelEdit() {
			this.editing = null
		},
		// ---- drag and drop ------------------------------------------------
		startDrag(item) {
			this.dragItem = item
		},
		endDrag() {
			this.dragItem = null
			this.dragOverSection = null
		},
		onSectionLeave(si) {
			if (this.dragOverSection === si) {
				this.dragOverSection = null
			}
		},
		// Remove a field from wherever it currently sits.
		detach(machineName) {
			for (const s of this.draft.sections) {
				const i = s.fields.indexOf(machineName)
				if (i !== -1) {
					s.fields.splice(i, 1)
				}
			}
		},
		onDropSection(si) {
			const item = this.dragItem
			this.dragOverSection = null
			if (!item) {
				return
			}
			this.detach(item.machineName)
			this.draft.sections[si].fields.push(item.machineName)
			this.dragItem = null
		},
		onDropBefore(si, idx) {
			const item = this.dragItem
			this.dragOverSection = null
			if (!item) {
				return
			}
			this.detach(item.machineName)
			// idx may have shifted after detach if same section; recompute safely.
			const target = this.draft.sections[si].fields
			const insertAt = Math.min(idx, target.length)
			target.splice(insertAt, 0, item.machineName)
			this.dragItem = null
		},
		onDropPalette() {
			const item = this.dragItem
			if (item && item.from === 'section') {
				this.detach(item.machineName)
			}
			this.endDrag()
		},
		removeFromSection(si, machineName) {
			const i = this.draft.sections[si].fields.indexOf(machineName)
			if (i !== -1) {
				this.draft.sections[si].fields.splice(i, 1)
			}
		},
		// ---- sections -----------------------------------------------------
		addSection() {
			this.draft.sections.push({ title: '', fields: [] })
		},
		removeSection(si) {
			// Removed section's fields return to the palette automatically.
			this.draft.sections.splice(si, 1)
			if (this.draft.sections.length === 0) {
				this.addSection()
			}
		},
		moveSection(si, dir) {
			const to = si + dir
			if (to < 0 || to >= this.draft.sections.length) {
				return
			}
			const [s] = this.draft.sections.splice(si, 1)
			this.draft.sections.splice(to, 0, s)
		},
		// ---- preview ------------------------------------------------------
		previewKind(machineName) {
			const f = this.fieldByName[machineName]
			if (!f) {
				return 'input'
			}
			switch (f.type) {
				case 'boolean': return 'bool'
				case 'select': case 'relation': case 'user': case 'group': return 'select'
				case 'multiselect': return 'multiselect'
				case 'file': return 'file'
				case 'longtext': return 'textarea'
				case 'computed': case 'auto': return 'readonly'
				default: return 'input'
			}
		},
		previewPlaceholder(machineName) {
			const f = this.fieldByName[machineName]
			const map = {
				date: t('dataforms', 'dd/mm/yyyy'), datetime: t('dataforms', 'dd/mm/yyyy, --:--'), time: '--:--',
				number: '0', currency: '0.00', percentage: '0', email: 'name@example.org', url: 'https://…', phone: '+ …',
			}
			return (f && map[f.type]) || ''
		},
		// ---- persistence --------------------------------------------------
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
				if (this.editing && this.editing.id) {
					const updated = await updateForm(this.editing.id, this.payload())
					const i = this.forms.findIndex((f) => f.id === updated.id)
					if (i !== -1) {
						this.forms.splice(i, 1, updated)
					}
				} else {
					this.forms.push(await createForm(this.registerId, this.payload()))
				}
				this.editing = null
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
.form-builder { max-width: 1100px; margin: 0 auto; padding: 20px 24px 48px; }
.header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.header h3 { margin: 0; }
.hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 2px 0 0; max-width: 620px; }
.centered { margin: 60px auto; }

/* list */
.form-list { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); overflow: hidden; }
.form-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-bottom: 1px solid var(--color-border); cursor: pointer; }
.form-row:last-child { border-bottom: none; }
.form-row:hover { background: var(--color-background-hover); }
.row-icon { color: var(--color-primary-element); flex: none; }
.form-title { font-weight: 600; }
.form-meta { color: var(--color-text-maxcontrast); font-size: 0.85em; }
.spacer { flex: 1; }

/* builder */
.builder-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.title-input {
	font-size: 1.25em; font-weight: 600; border: none; border-bottom: 2px solid var(--color-border);
	background: transparent; color: var(--color-main-text); padding: 4px 2px; min-width: 240px; flex: 0 1 380px;
}
.title-input:focus { outline: none; border-bottom-color: var(--color-primary-element); }
.placed-count { color: var(--color-text-maxcontrast); font-size: 0.86em; }

.builder-body { display: grid; grid-template-columns: 260px 1fr; gap: 18px; align-items: start; }

.palette {
	position: sticky; top: 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px);
	padding: 12px; background: var(--color-main-background); max-height: calc(100vh - 200px); overflow-y: auto;
}
.palette.drop-active { border-color: var(--color-error); background: var(--color-error, #9d3a3a11); }
.palette h4 { margin: 0 0 4px; }
.palette-hint { color: var(--color-text-maxcontrast); font-size: 0.8em; margin: 0 0 10px; }
.palette-search { width: 100%; margin-bottom: 10px; padding: 6px 10px; border: 2px solid var(--color-border-maxcontrast); border-radius: var(--border-radius, 6px); background: var(--color-main-background); color: var(--color-main-text); }
.palette-list { display: flex; flex-direction: column; gap: 6px; }
.palette-empty { color: var(--color-text-maxcontrast); font-size: 0.85em; padding: 8px 2px; }

.chip {
	display: flex; align-items: center; gap: 6px; padding: 7px 8px; border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px); background: var(--color-background-hover); cursor: grab;
}
.chip:active { cursor: grabbing; }
.chip-label { font-weight: 500; font-size: 0.9em; }
.chip-type { margin-left: auto; color: var(--color-text-maxcontrast); font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.02em; }
.grip { color: var(--color-text-maxcontrast); flex: none; }

.canvas { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.section {
	border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px);
	padding: 12px 14px; background: var(--color-main-background); transition: border-color 0.12s, background 0.12s;
}
.section.drop-active { border-color: var(--color-primary-element); background: var(--color-primary-element-light, var(--color-background-hover)); }
.section-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.section-title { flex: 1; font-weight: 600; border: none; border-bottom: 1px dashed var(--color-border); background: transparent; color: var(--color-main-text); padding: 4px 2px; }
.section-title:focus { outline: none; border-bottom-style: solid; border-bottom-color: var(--color-primary-element); }
.section-tools { display: flex; align-items: center; flex: none; }

.section-empty {
	border: 2px dashed var(--color-border); border-radius: var(--border-radius, 6px);
	padding: 22px; text-align: center; color: var(--color-text-maxcontrast); font-size: 0.9em;
}

.placed-list { display: flex; flex-direction: column; gap: 8px; }
.placed {
	display: flex; align-items: flex-start; gap: 8px; padding: 9px 10px; border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px); background: var(--color-main-background); cursor: grab;
}
.placed:hover { border-color: var(--color-primary-element); }
.placed:active { cursor: grabbing; }
.placed-main { flex: 1; min-width: 0; }
.placed-label { font-weight: 500; font-size: 0.92em; margin-bottom: 5px; }
.req { color: var(--color-error-text, var(--color-error)); }
.placed-x { border: none; background: none; cursor: pointer; font-size: 1.25em; line-height: 1; color: var(--color-text-maxcontrast); padding: 0 4px; }
.placed-x:hover { color: var(--color-error-text, var(--color-error)); }

/* faux control previews */
.preview { font-size: 0.84em; color: var(--color-text-maxcontrast); }
.faux-input, .faux-select, .faux-readonly {
	display: block; border: 1.5px solid var(--color-border-maxcontrast); border-radius: var(--border-radius, 6px);
	padding: 5px 8px; background: var(--color-background-hover); max-width: 320px;
}
.faux-area { display: block; height: 38px; border: 1.5px solid var(--color-border-maxcontrast); border-radius: var(--border-radius, 6px); background: var(--color-background-hover); max-width: 320px; }
.faux-readonly { background: var(--color-background-dark); font-style: italic; }
.faux-btn { display: inline-block; border: 1.5px solid var(--color-border-maxcontrast); border-radius: var(--border-radius, 6px); padding: 4px 10px; background: var(--color-background-hover); }
.preview-bool .radio { margin-right: 14px; }

.add-section { align-self: flex-start; }
.canvas-hint { color: var(--color-text-maxcontrast); font-size: 0.88em; margin: 4px 0 0; }

@media (max-width: 720px) {
	.builder-body { grid-template-columns: 1fr; }
	.palette { position: static; max-height: none; }
}
</style>
