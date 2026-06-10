<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!--
	Wrapper that lets a form be filled and submitted from *outside* the app — in
	a reference widget (Text, Talk, …). It reuses the exact same RecordForm the
	SPA uses (the single form renderer), loading the register's fields, rules and
	the chosen form, then opening the data-entry dialog over the current page.
-->
<template>
	<RecordForm v-if="ready"
		:register-id="registerId"
		:fields="fields"
		:rules="rules"
		:record="null"
		:form="form"
		@saved="onSaved"
		@close="$emit('close')" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import RecordForm from '../components/RecordForm.vue'
import { listFields } from '../api/fields.js'
import { listRules } from '../api/rules.js'
import { listForms } from '../api/forms.js'

export default {
	name: 'InlineForm',
	components: { RecordForm },
	props: {
		registerId: { type: Number, required: true },
		formId: { type: Number, default: null },
	},
	emits: ['close', 'saved'],
	data() {
		return { fields: [], rules: [], form: null, ready: false }
	},
	async mounted() {
		try {
			this.fields = await listFields(this.registerId)
			this.rules = await listRules(this.registerId).catch(() => [])
			if (this.formId) {
				const forms = await listForms(this.registerId).catch(() => [])
				this.form = forms.find((f) => f.id === this.formId) || null
			}
			this.ready = true
		} catch (e) {
			console.error(e)
			showError(t('dataforms', 'Could not open the form'))
			this.$emit('close')
		}
	},
	methods: {
		onSaved() {
			showSuccess(t('dataforms', 'Record added'))
			this.$emit('saved')
			this.$emit('close')
		},
	},
}
</script>
