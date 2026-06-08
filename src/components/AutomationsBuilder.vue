<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!--
	Automations (workflow): when a record is created/updated/deleted and an
	optional condition holds, run an action (notify or email). Manager-only.
	Conditions reuse the same field/operator/value shape as rules and filters.
-->
<template>
	<div class="automations">
		<div class="header">
			<div>
				<h3>{{ t('dataforms', 'Automations') }}</h3>
				<p class="hint">
					{{ t('dataforms', 'React to record changes: when something happens and your conditions match, run an action — notify someone, send an email, set a field, create folders, or call a webhook. Runs on the server.') }}
				</p>
			</div>
			<NcButton v-if="canManage" type="primary" @click="openAdd">
				<template #icon><PlusIcon :size="20" /></template>
				{{ t('dataforms', 'New automation') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" class="centered" :size="32" />

		<NcEmptyContent
			v-else-if="automations.length === 0"
			:name="t('dataforms', 'No automations yet')"
			:description="t('dataforms', 'Add one to notify people or send an email when records change.')">
			<template #icon><RobotIcon :size="20" /></template>
		</NcEmptyContent>

		<ul v-else class="auto-list">
			<li v-for="a in automations" :key="a.id" class="auto-row">
				<NcCheckboxRadioSwitch :model-value="a.enabled" type="switch" :disabled="!canManage" @update:model-value="toggle(a)" />
				<span class="auto-main">
					<span class="auto-name">{{ a.name }}</span>
					<span class="auto-desc">{{ triggerLabel(a.trigger) }} · {{ actionLabel(a.actionType) }}<span v-if="a.condition"> · {{ n('dataforms', '%n condition', '%n conditions', a.condition.rules.length) }}</span></span>
				</span>
				<span class="spacer" />
				<NcActions v-if="canManage">
					<NcActionButton @click="openEdit(a)"><template #icon><PencilIcon :size="20" /></template>{{ t('dataforms', 'Edit') }}</NcActionButton>
					<NcActionButton @click="remove(a)"><template #icon><DeleteIcon :size="20" /></template>{{ t('dataforms', 'Delete') }}</NcActionButton>
				</NcActions>
			</li>
		</ul>

		<NcDialog v-if="showDialog" :name="editing ? t('dataforms', 'Edit automation') : t('dataforms', 'New automation')" size="normal" :can-close="!saving" @closing="showDialog = false">
			<div class="auto-form">
				<NcTextField v-model="draft.name" :label="t('dataforms', 'Name')" :required="true" />

				<label class="block-label">{{ t('dataforms', 'When') }}</label>
				<NcSelect v-model="draft.trigger" :options="triggers" :reduce="(o) => o.id" label="label" :clearable="false" />

				<label class="block-label">{{ t('dataforms', 'Only if (optional)') }}</label>
				<div v-for="(c, i) in draft.conditions" :key="i" class="cond-row">
					<NcSelect v-model="c.field" :options="fieldOptions" :reduce="(o) => o.id" label="label" :clearable="false" class="c-field" :aria-label="t('dataforms', 'Condition field')" :placeholder="t('dataforms', 'Field')" />
					<div class="cond-row-2">
						<NcSelect v-model="c.op" :options="ops" :reduce="(o) => o.id" label="label" :clearable="false" class="c-op" :aria-label="t('dataforms', 'Operator')" />
						<NcSelect
							v-if="!['isEmpty', 'isNotEmpty'].includes(c.op) && optionsForField(c.field).length"
							v-model="c.value"
							:options="optionsForField(c.field)"
							:clearable="false"
							:taggable="true"
							:aria-label="t('dataforms', 'Value')"
							:placeholder="t('dataforms', 'Value')"
							class="c-val" />
						<NcTextField
							v-else-if="!['isEmpty', 'isNotEmpty'].includes(c.op)"
							v-model="c.value"
							:label="t('dataforms', 'Value')"
							class="c-val" />
						<NcButton type="tertiary" :aria-label="t('dataforms', 'Remove condition')" @click="draft.conditions.splice(i, 1)"><template #icon><CloseIcon :size="18" /></template></NcButton>
					</div>
				</div>
				<NcButton type="tertiary" @click="addCondition"><template #icon><PlusIcon :size="16" /></template>{{ t('dataforms', 'Add condition') }}</NcButton>

				<label class="block-label">{{ t('dataforms', 'Then') }}</label>
				<NcSelect v-model="draft.actionType" :options="actionTypes" :reduce="(o) => o.id" label="label" :clearable="false" />

				<template v-if="['notify', 'email'].includes(draft.actionType)">
					<label class="block-label">{{ t('dataforms', 'Recipients') }}</label>
					<NcSelect
						v-model="draft.recipients"
						:options="recipientOptions"
						:loading="searching"
						:multiple="true"
						:filterable="false"
						label="label"
						:placeholder="t('dataforms', 'Search users…')"
						@search="onUserSearch" />
					<NcTextField v-if="draft.actionType === 'email'" v-model="draft.subject" :label="t('dataforms', 'Email subject')" />
					<NcTextArea v-model="draft.message" :label="draft.actionType === 'email' ? t('dataforms', 'Email body') : t('dataforms', 'Message')" />
				</template>

				<template v-else-if="draft.actionType === 'set_field'">
					<label class="block-label">{{ t('dataforms', 'Field to set') }}</label>
					<NcSelect v-model="draft.setField" :options="settableFields" :reduce="(o) => o.id" label="label" :clearable="false" :placeholder="t('dataforms', 'Field')" />
					<NcTextField v-model="draft.setValue" :label="t('dataforms', 'Value')" />
				</template>

				<template v-else-if="draft.actionType === 'provision_folders'">
					<label class="block-label">{{ t('dataforms', 'Base folder (optional)') }}</label>
					<NcTextField v-model="draft.basePath" :placeholder="t('dataforms', 'e.g. Clients')" />
					<label class="block-label">{{ t('dataforms', 'Folders to create (one per line)') }}</label>
					<NcTextArea v-model="draft.folderLines" :placeholder="folderPlaceholder" />
					<p class="hint">
						{{ t('dataforms', 'Created in the record author’s Files. Use {field} to insert a value, e.g. {client}/Contracts. Existing folders are reused.') }}
						<br>{{ t('dataforms', 'Fields:') }} <code>{{ machineNames }}</code>
					</p>
				</template>

				<template v-else-if="draft.actionType === 'apply_template'">
					<label class="block-label">{{ t('dataforms', 'Template folder') }}</label>
					<NcTextField v-model="draft.templateSource" :placeholder="t('dataforms', 'e.g. Templates/Meeting')" />
					<label class="block-label">{{ t('dataforms', 'Copy into') }}</label>
					<NcTextField v-model="draft.templateDest" :placeholder="t('dataforms', 'e.g. Clients/{client}')" />
					<p class="hint">
						{{ t('dataforms', 'Copies the contents of the template folder into the destination, in the record author’s Files. Use {field} placeholders; existing files are kept.') }}
						<br>{{ t('dataforms', 'Fields:') }} <code>{{ machineNames }}</code>
					</p>
				</template>

				<template v-else-if="draft.actionType === 'add_calendar_event'">
					<label class="block-label">{{ t('dataforms', 'Event title') }}</label>
					<NcTextField v-model="draft.eventTitle" :placeholder="t('dataforms', 'e.g. Kickoff: {client}')" />
					<label class="block-label">{{ t('dataforms', 'Start date (from a field)') }}</label>
					<NcSelect v-model="draft.startField" :options="dateFieldOptions" :reduce="(o) => o.id" label="label" :clearable="false" :placeholder="t('dataforms', 'Pick a date field')" />
					<label class="block-label">{{ t('dataforms', 'Duration') }}</label>
					<NcSelect v-model="draft.duration" :options="durationOptions" :reduce="(o) => o.id" label="label" :clearable="false" />
					<label class="block-label">{{ t('dataforms', 'Calendar (optional)') }}</label>
					<NcTextField v-model="draft.calendar" :placeholder="t('dataforms', 'Calendar name — blank for the author’s default')" />
					<NcTextArea v-model="draft.eventDescription" :label="t('dataforms', 'Description (optional)')" />
					<p class="hint">
						{{ t('dataforms', 'Added to the record author’s calendar. Use {field} in the title/description.') }}
						<br>{{ t('dataforms', 'Fields:') }} <code>{{ machineNames }}</code>
					</p>
				</template>

				<template v-else-if="draft.actionType === 'webhook'">
					<label class="block-label">{{ t('dataforms', 'Webhook URL') }}</label>
					<NcTextField v-model="draft.url" placeholder="https://example.org/hook" />
					<NcTextField v-model="draft.secret" :label="t('dataforms', 'Shared secret (optional)')" />
					<p class="hint">{{ t('dataforms', 'A POST with the record data is sent here. If a secret is set, the body is signed (HMAC-SHA256) in the X-DataForms-Signature header.') }}</p>
				</template>
			</div>
			<template #actions>
				<NcButton :disabled="saving" @click="showDialog = false">{{ t('dataforms', 'Cancel') }}</NcButton>
				<NcButton type="primary" :disabled="saving || !canSave" @click="submit">
					{{ editing ? t('dataforms', 'Save') : t('dataforms', 'Add') }}
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
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import RobotIcon from 'vue-material-design-icons/Cog.vue'

import { listAutomations, createAutomation, updateAutomation, deleteAutomation, TRIGGERS, ACTION_TYPES } from '../api/automations.js'
import { listFields } from '../api/fields.js'
import { searchSharees } from '../api/shares.js'
import { FILTER_OPS } from '../api/rules.js'

const blank = () => ({ name: '', trigger: 'create', conditions: [], actionType: 'notify', recipients: [], subject: '', message: '', setField: '', setValue: '', url: '', secret: '', basePath: '', folderLines: '', templateSource: '', templateDest: '', eventTitle: '', startField: '', duration: 60, calendar: '', eventDescription: '' })

export default {
	name: 'AutomationsBuilder',
	components: {
		NcActions, NcActionButton, NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField, NcTextArea,
		PlusIcon, PencilIcon, DeleteIcon, CloseIcon, RobotIcon,
	},
	props: {
		registerId: { type: Number, required: true },
		canManage: { type: Boolean, default: false },
	},
	data() {
		return {
			automations: [],
			fields: [],
			loading: true,
			showDialog: false,
			editing: null,
			saving: false,
			draft: blank(),
			triggers: TRIGGERS.map((x) => ({ ...x, label: t('dataforms', x.label) })),
			actionTypes: ACTION_TYPES.map((x) => ({ ...x, label: t('dataforms', x.label) })),
			ops: FILTER_OPS,
			recipientOptions: [],
			searching: false,
			searchTimer: null,
			durationOptions: [
				{ id: 30, label: t('dataforms', '30 minutes') },
				{ id: 60, label: t('dataforms', '1 hour') },
				{ id: 120, label: t('dataforms', '2 hours') },
				{ id: 0, label: t('dataforms', 'All day') },
			],
		}
	},
	computed: {
		fieldOptions() {
			return this.fields
				.filter((f) => !['file', 'relation', 'auto'].includes(f.type))
				.map((f) => ({ id: f.machineName, label: f.label }))
		},
		// Field machine names shown as a hint for {field} folder templates.
		machineNames() {
			return this.fields.map((f) => '{' + f.machineName + '}').join(' ')
		},
		folderPlaceholder() {
			const first = this.fields[0]?.machineName ?? 'name'
			return '{' + first + '}\n{' + first + '}/Contracts\n{' + first + '}/Correspondence'
		},
		// Date/datetime fields usable as a calendar event's start.
		dateFieldOptions() {
			return this.fields
				.filter((f) => ['date', 'datetime'].includes(f.type))
				.map((f) => ({ id: f.machineName, label: f.label }))
		},
		// Fields the set-field action can write (excludes join-table & derived types).
		settableFields() {
			return this.fields
				.filter((f) => !['file', 'relation', 'auto', 'computed'].includes(f.type))
				.map((f) => ({ id: f.machineName, label: f.label }))
		},
		canSave() {
			if (!this.draft.name.trim()) {
				return false
			}
			const a = this.draft.actionType
			if (['notify', 'email'].includes(a)) {
				return this.draft.recipients.length > 0
			}
			if (a === 'set_field') {
				return !!this.draft.setField
			}
			if (a === 'provision_folders') {
				return this.draft.folderLines.split('\n').some((s) => s.trim() !== '')
			}
			if (a === 'apply_template') {
				return !!this.draft.templateSource.trim() && !!this.draft.templateDest.trim()
			}
			if (a === 'add_calendar_event') {
				return !!this.draft.eventTitle.trim() && !!this.draft.startField
			}
			if (a === 'webhook') {
				return /^https?:\/\//i.test(this.draft.url.trim())
			}
			return false
		},
	},
	watch: {
		registerId() { this.load() },
	},
	mounted() { this.load() },
	beforeUnmount() { clearTimeout(this.searchTimer) },
	methods: {
		t,
		n,
		triggerLabel(id) { return this.triggers.find((x) => x.id === id)?.label ?? id },
		actionLabel(id) { return this.actionTypes.find((x) => x.id === id)?.label ?? id },
		// Predefined options for a select/multiselect field, so a condition value
		// can be picked from a dropdown instead of typed.
		optionsForField(machineName) {
			const f = this.fields.find((x) => x.machineName === machineName)
			return (f && Array.isArray(f.config?.options)) ? f.config.options : []
		},
		async load() {
			this.loading = true
			try {
				this.fields = await listFields(this.registerId).catch(() => [])
				this.automations = await listAutomations(this.registerId)
			} catch (e) {
				showError(t('dataforms', 'Could not load automations'))
				console.error(e)
			} finally {
				this.loading = false
			}
		},
		openAdd() {
			this.editing = null
			this.draft = blank()
			this.recipientOptions = []
			this.showDialog = true
		},
		openEdit(a) {
			this.editing = a
			const cfg = a.actionConfig || {}
			const recipients = (cfg.users || []).map((u) => ({ id: u, label: u }))
			this.draft = {
				name: a.name,
				trigger: a.trigger,
				conditions: a.condition ? a.condition.rules.map((r) => ({ ...r })) : [],
				actionType: a.actionType,
				recipients,
				subject: cfg.subject || '',
				message: cfg.message || cfg.body || '',
				setField: cfg.field || '',
				setValue: cfg.value ?? '',
				url: cfg.url || '',
				secret: cfg.secret || '',
				basePath: cfg.basePath || '',
				folderLines: Array.isArray(cfg.folders) ? cfg.folders.join('\n') : '',
				templateSource: cfg.source || '',
				templateDest: cfg.destination || '',
				eventTitle: cfg.title || '',
				startField: cfg.startField || '',
				duration: cfg.allDay ? 0 : (cfg.durationMinutes ?? 60),
				calendar: cfg.calendar || '',
				eventDescription: cfg.description || '',
			}
			this.recipientOptions = recipients
			this.showDialog = true
		},
		addCondition() {
			this.draft.conditions.push({ field: this.fieldOptions[0]?.id ?? '', op: 'eq', value: '' })
		},
		onUserSearch(query) {
			clearTimeout(this.searchTimer)
			if (query.trim() === '') { return }
			this.searchTimer = setTimeout(async () => {
				this.searching = true
				try {
					const res = await searchSharees(this.registerId, query.trim())
					this.recipientOptions = res.filter((r) => r.type === 'user')
				} catch (e) {
					console.error(e)
				} finally {
					this.searching = false
				}
			}, 250)
		},
		payload() {
			const conditions = this.draft.conditions.filter((c) => c.field)
			let config = {}
			if (this.draft.actionType === 'email') {
				config = { users: this.draft.recipients.map((r) => r.id), subject: this.draft.subject, body: this.draft.message }
			} else if (this.draft.actionType === 'notify') {
				config = { users: this.draft.recipients.map((r) => r.id), message: this.draft.message }
			} else if (this.draft.actionType === 'set_field') {
				config = { field: this.draft.setField, value: this.draft.setValue }
			} else if (this.draft.actionType === 'provision_folders') {
				config = {
					basePath: this.draft.basePath.trim(),
					folders: this.draft.folderLines.split('\n').map((s) => s.trim()).filter((s) => s !== ''),
				}
			} else if (this.draft.actionType === 'apply_template') {
				config = { source: this.draft.templateSource.trim(), destination: this.draft.templateDest.trim() }
			} else if (this.draft.actionType === 'add_calendar_event') {
				config = {
					calendar: this.draft.calendar.trim(),
					title: this.draft.eventTitle.trim(),
					startField: this.draft.startField,
					durationMinutes: this.draft.duration === 0 ? 0 : this.draft.duration,
					allDay: this.draft.duration === 0,
					description: this.draft.eventDescription,
				}
			} else if (this.draft.actionType === 'webhook') {
				config = { url: this.draft.url.trim(), secret: this.draft.secret }
			}
			return {
				name: this.draft.name.trim(),
				trigger: this.draft.trigger,
				actionType: this.draft.actionType,
				condition: conditions.length ? { logic: 'and', rules: conditions } : null,
				actionConfig: config,
				enabled: true,
			}
		},
		async submit() {
			if (!this.draft.name.trim() || this.saving) { return }
			this.saving = true
			try {
				if (this.editing) {
					const p = this.payload()
					const updated = await updateAutomation(this.editing.id, p)
					const i = this.automations.findIndex((x) => x.id === updated.id)
					if (i !== -1) { this.automations.splice(i, 1, updated) }
				} else {
					this.automations.push(await createAutomation(this.registerId, this.payload()))
				}
				this.showDialog = false
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not save the automation'))
				console.error(e)
			} finally {
				this.saving = false
			}
		},
		async toggle(a) {
			try {
				const updated = await updateAutomation(a.id, { enabled: !a.enabled })
				const i = this.automations.findIndex((x) => x.id === a.id)
				if (i !== -1) { this.automations.splice(i, 1, updated) }
			} catch (e) {
				showError(t('dataforms', 'Could not update the automation'))
				console.error(e)
			}
		},
		async remove(a) {
			if (!window.confirm(t('dataforms', 'Delete automation “{name}”?', { name: a.name }))) { return }
			try {
				await deleteAutomation(a.id)
				this.automations = this.automations.filter((x) => x.id !== a.id)
			} catch (e) {
				showError(t('dataforms', 'Could not delete the automation'))
				console.error(e)
			}
		},
	},
}
</script>

<style scoped>
.automations { max-width: 820px; margin: 0 auto; padding: 24px; }
.header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.header h3 { margin: 0; }
.hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 2px 0 0; max-width: 580px; }
.centered { margin: 60px auto; }
.auto-list { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); overflow: hidden; }
.auto-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--color-border); }
.auto-row:last-child { border-bottom: none; }
.auto-main { display: flex; flex-direction: column; min-width: 0; }
.auto-name { font-weight: 600; }
.auto-desc { color: var(--color-text-maxcontrast); font-size: 0.85em; }
.spacer { flex: 1; }
.auto-form { display: flex; flex-direction: column; gap: 10px; min-width: min(520px, 84vw); padding: 8px 2px; }
.block-label { font-weight: 600; font-size: 0.85em; margin-top: 6px; }
/* Field on its own line; operator + value + remove on a second line, so the
   value control always keeps a usable width in the narrow dialog. */
.cond-row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.cond-row-2 { display: flex; gap: 6px; align-items: center; }
.cond-row-2 .c-op { flex: 0 1 150px; min-width: 110px; }
.cond-row-2 .c-val { flex: 1 1 auto; min-width: 140px; }
</style>
