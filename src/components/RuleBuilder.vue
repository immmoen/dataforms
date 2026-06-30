<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="rule-builder">
		<div class="header">
			<div>
				<h3>{{ t('dataforms', 'Conditional rules') }}</h3>
				<p class="hint">
					{{ t('dataforms', 'Show/hide fields, make them required, set defaults, validate, or compute values. Rules run live in the form and are re-checked on the server.') }}
				</p>
			</div>
			<NcButton v-if="canManage"
				variant="primary"
				:disabled="fields.length === 0"
				@click="openAdd">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('dataforms', 'Add rule') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" class="centered" :size="32" />

		<NcEmptyContent v-else-if="fields.length === 0"
			:name="t('dataforms', 'Add fields first')"
			:description="t('dataforms', 'Rules act on fields, so define the schema before adding rules.')">
			<template #icon>
				<FlashIcon :size="20" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="rules.length === 0"
			:name="t('dataforms', 'No rules yet')"
			:description="t('dataforms', 'Add a rule to make this form smart — e.g. compute a score, or require a field only when another has a certain value.')">
			<template #icon>
				<FlashIcon :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="rule-list">
			<li v-for="rule in rules" :key="rule.id" class="rule-row">
				<span class="effect-badge" :class="'e-' + rule.effect">{{ effectLabel(rule.effect) }}</span>
				<span class="summary">{{ ruleSummary(rule) }}</span>
				<span class="spacer" />
				<NcActions v-if="canManage">
					<NcActionButton @click="openEdit(rule)">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('dataforms', 'Edit') }}
					</NcActionButton>
					<NcActionButton @click="remove(rule)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('dataforms', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>

		<!-- Add rule dialog -->
		<NcDialog v-if="showAdd"
			:name="editingRule ? t('dataforms', 'Edit rule') : t('dataforms', 'Add rule')"
			size="large"
			:can-close="!saving"
			@closing="showAdd = false">
			<div class="add-form">
				<div class="row2">
					<div class="block">
						<label class="block-label">{{ t('dataforms', 'Effect') }}</label>
						<NcSelect v-model="draft.effect"
							:options="effects"
							:reduce="(o) => o.id"
							label="label"
							:aria-label="t('dataforms', 'Effect')"
							:clearable="false" />
					</div>
					<div class="block">
						<label class="block-label">{{ t('dataforms', 'Target field') }}</label>
						<NcSelect v-model="draft.target"
							:options="fieldOptions"
							:reduce="(o) => o.id"
							label="label"
							:aria-label="t('dataforms', 'Target field')"
							:clearable="false" />
					</div>
				</div>

				<!-- conditions (show / require / set_value / validate) -->
				<div v-if="usesConditions" class="conditions">
					<div class="cond-head">
						<span class="block-label">{{ t('dataforms', 'When') }}</span>
						<NcSelect v-model="draft.logic"
							:options="['and', 'or']"
							:aria-label="t('dataforms', 'Condition logic')"
							:clearable="false"
							class="logic-sel" />
					</div>
					<div v-for="(c, i) in draft.conditions" :key="i" class="cond-row">
						<NcSelect v-model="c.field"
							:options="fieldOptions"
							:reduce="(o) => o.id"
							label="label"
							:aria-label="t('dataforms', 'Condition field')"
							:clearable="false"
							class="cond-field" />
						<NcSelect v-model="c.op"
							:options="ops"
							:reduce="(o) => o.id"
							label="label"
							:aria-label="t('dataforms', 'Operator')"
							:clearable="false"
							class="cond-op" />
						<template v-if="!['isEmpty', 'isNotEmpty'].includes(c.op)">
							<NcSelect v-if="optionsForField(c.field).length"
								v-model="c.value"
								:options="optionsForField(c.field)"
								:clearable="false"
								:taggable="true"
								:aria-label="t('dataforms', 'Value')"
								:placeholder="t('dataforms', 'Value')"
								class="cond-val" />
							<NcTextField v-else
								v-model="c.value"
								:label="t('dataforms', 'Value')"
								class="cond-val" />
						</template>
						<NcButton variant="tertiary" :aria-label="t('dataforms', 'Remove condition')" @click="draft.conditions.splice(i, 1)">
							<template #icon>
								<DeleteIcon :size="18" />
							</template>
						</NcButton>
					</div>
					<NcButton variant="tertiary" @click="addCondition">
						<template #icon>
							<PlusIcon :size="18" />
						</template>
						{{ t('dataforms', 'Add condition') }}
					</NcButton>
				</div>

				<!-- set_value -->
				<div v-if="draft.effect === 'set_value'" class="block">
					<NcTextField v-model="draft.value" :label="t('dataforms', 'Value to set')" />
				</div>

				<!-- compute -->
				<div v-if="draft.effect === 'compute'" class="block">
					<NcTextField v-model="draft.expression" :label="t('dataforms', 'Expression')" />
					<p class="expr-hint">
						{{ t('dataforms', 'Use field names and functions: e.g. likelihood * impact, round(price * 1.2, 2), if(qty > 10, "bulk", "single").') }}
						<br>{{ t('dataforms', 'Fields:') }} <code>{{ machineNames }}</code>
					</p>
				</div>

				<!-- validate -->
				<div v-if="draft.effect === 'validate'" class="validation">
					<div class="block">
						<label class="block-label">{{ t('dataforms', 'Check') }}</label>
						<NcSelect v-model="draft.validation.kind"
							:options="['regex', 'range', 'expression']"
							:aria-label="t('dataforms', 'Validation check')"
							:clearable="false" />
					</div>
					<NcTextField v-if="draft.validation.kind === 'regex'" v-model="draft.validation.pattern" :label="t('dataforms', 'Pattern (regex)')" />
					<div v-if="draft.validation.kind === 'range'" class="row2">
						<NcTextField v-model="draft.validation.min" type="number" :label="t('dataforms', 'Min')" />
						<NcTextField v-model="draft.validation.max" type="number" :label="t('dataforms', 'Max')" />
					</div>
					<NcTextField v-if="draft.validation.kind === 'expression'" v-model="draft.validation.expression" :label="t('dataforms', 'Must be true (expression)')" />
					<NcTextField v-model="draft.validation.message" :label="t('dataforms', 'Error message')" />
				</div>
			</div>

			<template #actions>
				<NcButton :disabled="saving" @click="showAdd = false">
					{{ t('dataforms', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !draft.target" @click="submit">
					{{ editingRule ? t('dataforms', 'Save') : t('dataforms', 'Add rule') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
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
import FlashIcon from 'vue-material-design-icons/Flash.vue'

import { listRules, createRule, updateRule, deleteRule, RULE_EFFECTS, CONDITION_OPS } from '../api/rules.js'
import { listFields } from '../api/fields.js'

const emptyDraft = () => ({
	effect: 'show',
	target: '',
	logic: 'and',
	conditions: [{ field: '', op: 'eq', value: '' }],
	value: '',
	expression: '',
	validation: { kind: 'regex', pattern: '', min: '', max: '', expression: '', message: '' },
})

export default {
	name: 'RuleBuilder',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		PlusIcon,
		PencilIcon,
		DeleteIcon,
		FlashIcon,
	},
	props: {
		registerId: { type: Number, required: true },
		canManage: { type: Boolean, default: false },
	},
	data() {
		return {
			/** @type {import('@/types/models').Rule[]} */
			rules: [],
			/** @type {import('@/types/models').Field[]} */
			fields: [],
			loading: true,
			showAdd: false,
			/** @type {import('@/types/models').Rule|null} */
			editingRule: null,
			saving: false,
			draft: emptyDraft(),
			effects: RULE_EFFECTS,
			ops: CONDITION_OPS,
		}
	},
	computed: {
		fieldOptions() {
			return this.fields.map((f) => ({ id: f.machineName, label: f.label }))
		},
		machineNames() {
			return this.fields.map((f) => f.machineName).join(', ')
		},
		usesConditions() {
			return ['show', 'require', 'set_value', 'validate'].includes(this.draft.effect)
		},
	},
	watch: {
		registerId() { this.load() },
	},
	mounted() { this.load() },
	methods: {
		t,
		effectLabel(id) { return RULE_EFFECTS.find((e) => e.id === id)?.label ?? id },
		async load() {
			this.loading = true
			try {
				this.fields = await listFields(this.registerId)
				this.rules = await listRules(this.registerId)
			} catch (e) {
				showError(t('dataforms', 'Could not load rules'))
				console.error(e)
			} finally {
				this.loading = false
			}
		},
		optionsForField(machineName) {
			const field = this.fields.find((f) => f.machineName === machineName)
			return /** @type {any[]} */ (field?.config?.options ?? [])
		},
		openAdd() {
			this.editingRule = null
			this.draft = emptyDraft()
			this.draft.target = this.fields[0]?.machineName ?? ''
			this.showAdd = true
		},
		openEdit(rule) {
			this.editingRule = rule
			this.draft = {
				effect: rule.effect,
				target: rule.target,
				logic: rule.conditions?.logic ?? 'and',
				conditions: (rule.conditions?.rules ?? []).length
					? rule.conditions.rules.map((c) => ({ field: c.field, op: c.op, value: c.value ?? '' }))
					: [{ field: this.fields[0]?.machineName ?? '', op: 'eq', value: '' }],
				value: rule.value ?? '',
				expression: rule.expression ?? '',
				validation: { kind: 'regex', pattern: '', min: '', max: '', expression: '', message: '', ...(rule.validation ?? {}) },
			}
			this.showAdd = true
		},
		addCondition() {
			this.draft.conditions.push({ field: this.fields[0]?.machineName ?? '', op: 'eq', value: '' })
		},
		buildPayload() {
			const d = this.draft
			const payload = { effect: d.effect, target: d.target }
			if (this.usesConditions) {
				payload.conditions = {
					logic: d.logic,
					rules: d.conditions
						.filter((c) => c.field)
						.map((c) => ({
							field: c.field,
							op: c.op,
							// "is one of" takes a comma-separated list -> array
							value: c.op === 'in' ? String(c.value ?? '').split(',').map((s) => s.trim()).filter(Boolean) : c.value,
						})),
				}
			}
			if (d.effect === 'set_value') payload.value = d.value
			if (d.effect === 'compute') payload.expression = d.expression
			if (d.effect === 'validate') payload.validation = d.validation
			return payload
		},
		async submit() {
			if (!this.draft.target || this.saving) return
			this.saving = true
			try {
				if (this.editingRule) {
					const updated = await updateRule(this.editingRule.id, this.buildPayload())
					const i = this.rules.findIndex((r) => r.id === updated.id)
					if (i !== -1) this.rules.splice(i, 1, updated)
				} else {
					this.rules.push(await createRule(this.registerId, this.buildPayload()))
				}
				this.showAdd = false
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not save the rule'))
				console.error(e)
			} finally {
				this.saving = false
			}
		},
		async remove(rule) {
			if (!window.confirm(t('dataforms', 'Delete this rule?'))) return
			try {
				await deleteRule(rule.id)
				this.rules = this.rules.filter((r) => r.id !== rule.id)
			} catch (e) {
				showError(t('dataforms', 'Could not delete the rule'))
				console.error(e)
			}
		},
		conditionsText(rule) {
			const c = rule.conditions
			if (!c || !c.rules || c.rules.length === 0) return t('dataforms', 'always')
			const opLabel = (id) => CONDITION_OPS.find((o) => o.id === id)?.label ?? id
			return c.rules
				.map((r) => `${r.field} ${opLabel(r.op)} ${['isEmpty', 'isNotEmpty'].includes(r.op) ? '' : (r.value ?? '')}`.trim())
				.join(` ${c.logic || 'and'} `)
		},
		ruleSummary(rule) {
			switch (rule.effect) {
			case 'show': return t('dataforms', 'Show {target} when {cond}', { target: rule.target, cond: this.conditionsText(rule) })
			case 'require': return t('dataforms', 'Require {target} when {cond}', { target: rule.target, cond: this.conditionsText(rule) })
			case 'set_value': return t('dataforms', 'Set {target} = "{value}" when {cond}', { target: rule.target, value: rule.value, cond: this.conditionsText(rule) })
			case 'compute': return `${rule.target} = ${rule.expression}`
			case 'validate': return t('dataforms', 'Validate {target} ({kind})', { target: rule.target, kind: rule.validation?.kind ?? '' })
			default: return rule.target
			}
		},
	},
}
</script>

<style scoped>
.rule-builder { max-width: 860px; margin: 0 auto; padding: 24px; }

.header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }

.header h3 { margin: 0; }

.hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 2px 0 0; max-width: 560px; }

.centered { margin: 60px auto; }

.rule-list { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); overflow: hidden; }

.rule-row { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-bottom: 1px solid var(--color-border); }

.rule-row:last-child { border-bottom: none; }

.effect-badge { font-size: 0.72em; font-weight: 700; padding: 2px 9px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.03em; background: var(--color-background-dark); color: var(--color-text-maxcontrast); white-space: nowrap; }

.e-compute { background: var(--color-primary-element-light); color: var(--color-primary-element); }

.e-show { background: #e6f4ea; color: #2d7d46; }

.e-require { background: #fbf3e0; color: #a06800; }

.e-validate { background: #fbe9ea; color: #c5343a; }

.summary { font-size: 0.92em; }

.spacer { flex: 1; }

.add-form { display: flex; flex-direction: column; gap: 16px; min-width: min(560px, 84vw); padding: 8px 2px; }

.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

.block-label { display: block; font-weight: 600; font-size: 0.88em; margin-bottom: 4px; }

.conditions { border-inline-start: 3px solid var(--color-primary-element); padding-inline-start: 14px; display: flex; flex-direction: column; gap: 8px; }

.cond-head { display: flex; align-items: center; gap: 10px; }

.logic-sel { width: 90px; }
/* Wrap so the value control keeps a usable width in the narrow dialog: the
   field takes the first line, operator + value + remove flow onto the next. */
.cond-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }

.cond-row .cond-field { flex: 1 1 100%; }

.cond-row .cond-op { flex: 0 1 150px; min-width: 110px; }

.cond-row .cond-val { flex: 1 1 160px; min-width: 140px; }

.expr-hint { color: var(--color-text-maxcontrast); font-size: 0.8em; margin: 6px 0 0; }

.validation { display: flex; flex-direction: column; gap: 12px; }
</style>
