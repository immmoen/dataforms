/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin → DataForms settings: wires the cross-app service-account form and the
 * instance-wide automation settings (enabled actions + limits) to the OCS
 * endpoints. Vanilla JS (no Vue) — the forms are rendered server-side.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)
const cfg = { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }

// Display labels for the action types (kept in sync with src/api/automations.js).
const ACTION_LABELS = () => ({
	notify: t('dataforms', 'Send a notification'),
	email: t('dataforms', 'Send an email'),
	set_field: t('dataforms', 'Set a field'),
	provision_folders: t('dataforms', 'Create folders'),
	apply_template: t('dataforms', 'Copy template files'),
	add_calendar_event: t('dataforms', 'Add a calendar event'),
	create_talk_room: t('dataforms', 'Create a Talk conversation'),
	create_deck_board: t('dataforms', 'Create a Deck board'),
	webhook: t('dataforms', 'Call a webhook'),
})

function initServiceAccount() {
	const root = document.getElementById('dataforms-service-account')
	if (!root) {
		return
	}
	const $ = (id) => root.querySelector('#' + id)
	const urlField = $('df-sa-url')
	const userField = $('df-sa-user')
	const passField = $('df-sa-pass')
	const status = $('df-sa-status')
	const saveBtn = $('df-sa-save')
	const testBtn = $('df-sa-test')
	const clearBtn = $('df-sa-clear')

	const setStatus = (msg, kind) => {
		status.textContent = msg
		status.dataset.kind = kind || ''
	}

	const refresh = async () => {
		try {
			const r = (await axios.get(url('service-account'), cfg)).data.ocs.data
			urlField.value = r.internalUrl || ''
			userField.value = r.username || ''
			passField.placeholder = r.hasPassword ? '••••••••  ' + t('dataforms', '(leave blank to keep)') : ''
			setStatus(r.configured ? t('dataforms', 'Configured') : t('dataforms', 'Not configured'), r.configured ? 'ok' : '')
		} catch (e) {
			setStatus(t('dataforms', 'Could not load settings'), 'err')
		}
	}

	saveBtn?.addEventListener('click', async () => {
		saveBtn.disabled = true
		try {
			const r = (await axios.post(url('service-account'), {
				internalUrl: urlField.value.trim(),
				username: userField.value.trim(),
				password: passField.value,
			}, cfg)).data.ocs.data
			passField.value = ''
			await refresh()
			setStatus(r.configured ? t('dataforms', 'Saved') : t('dataforms', 'Saved — add the app password to finish'), r.configured ? 'ok' : 'err')
		} catch (e) {
			setStatus(t('dataforms', 'Save failed'), 'err')
		} finally {
			saveBtn.disabled = false
		}
	})

	testBtn?.addEventListener('click', async () => {
		testBtn.disabled = true
		setStatus(t('dataforms', 'Testing…'), '')
		try {
			const r = (await axios.post(url('service-account/test'), {}, cfg)).data.ocs.data
			setStatus(r.ok ? t('dataforms', 'Connection OK') : t('dataforms', 'Test failed (HTTP {status})', { status: r.status }), r.ok ? 'ok' : 'err')
		} catch (e) {
			setStatus(t('dataforms', 'Test failed'), 'err')
		} finally {
			testBtn.disabled = false
		}
	})

	clearBtn?.addEventListener('click', async () => {
		if (!window.confirm(t('dataforms', 'Remove the service account?'))) {
			return
		}
		try {
			await axios.delete(url('service-account'), cfg)
			urlField.value = ''
			userField.value = ''
			passField.value = ''
			passField.placeholder = ''
			setStatus(t('dataforms', 'Removed'), '')
			// Reflect Talk/Deck availability in the automation panel.
			initAutomationConfig()
		} catch (e) {
			setStatus(t('dataforms', 'Could not remove'), 'err')
		}
	})

	refresh()
}

function initAutomationConfig() {
	const root = document.getElementById('dataforms-automation')
	if (!root) {
		return
	}
	const $ = (id) => root.querySelector('#' + id)
	const actionsBox = $('df-auto-actions')
	const status = $('df-auto-status')
	const saveBtn = $('df-auto-save')
	const labels = ACTION_LABELS()

	const setStatus = (msg, kind) => {
		status.textContent = msg
		status.dataset.kind = kind || ''
	}

	const render = (data) => {
		actionsBox.textContent = ''
		for (const a of data.actions || []) {
			const row = document.createElement('div')
			row.className = 'df-auto-action'

			const cb = document.createElement('input')
			cb.type = 'checkbox'
			cb.id = 'df-act-' + a.type
			cb.checked = !!a.enabled
			cb.dataset.action = a.type

			const label = document.createElement('label')
			label.setAttribute('for', cb.id)
			label.textContent = labels[a.type] || a.type

			row.appendChild(cb)
			row.appendChild(label)

			if (a.needsServiceAccount && !data.serviceAccountConfigured) {
				const note = document.createElement('span')
				note.className = 'df-auto-note'
				note.textContent = t('dataforms', '— needs the service account above')
				row.appendChild(note)
			}
			actionsBox.appendChild(row)
		}

		root.querySelectorAll('input[data-limit]').forEach((input) => {
			const key = input.dataset.limit
			input.value = data.limits?.[key] ?? ''
			input.placeholder = data.defaults?.[key] ?? ''
		})
		const deck = $('df-auto-deckcols')
		if (deck) {
			deck.value = data.deckColumns || ''
			deck.placeholder = data.defaultDeckColumns || ''
		}
	}

	const refresh = async () => {
		try {
			render((await axios.get(url('admin/automation'), cfg)).data.ocs.data)
		} catch (e) {
			setStatus(t('dataforms', 'Could not load settings'), 'err')
		}
	}

	saveBtn?.addEventListener('click', async () => {
		saveBtn.disabled = true
		setStatus(t('dataforms', 'Saving…'), '')
		const disabled = []
		actionsBox.querySelectorAll('input[data-action]').forEach((cb) => {
			if (!cb.checked) {
				disabled.push(cb.dataset.action)
			}
		})
		const limits = {}
		root.querySelectorAll('input[data-limit]').forEach((input) => {
			const v = parseInt(input.value, 10)
			if (!Number.isNaN(v) && v > 0) {
				limits[input.dataset.limit] = v
			}
		})
		try {
			render((await axios.put(url('admin/automation'), {
				disabled,
				limits,
				deckColumns: ($('df-auto-deckcols')?.value || '').trim(),
			}, cfg)).data.ocs.data)
			setStatus(t('dataforms', 'Saved'), 'ok')
		} catch (e) {
			setStatus(t('dataforms', 'Save failed'), 'err')
		} finally {
			saveBtn.disabled = false
		}
	})

	refresh()
}

document.addEventListener('DOMContentLoaded', () => {
	initServiceAccount()
	initAutomationConfig()
})
