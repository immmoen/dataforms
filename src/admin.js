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
	const list = root.querySelector('#df-sa-list')
	const addBtn = root.querySelector('#df-sa-add')

	const setRowStatus = (row, msg, kind) => {
		const s = row.querySelector('.df-sa-status')
		s.textContent = msg
		s.dataset.kind = kind || ''
	}

	const buildRow = (acc) => {
		const row = document.createElement('div')
		row.className = 'df-sa-account'
		row.dataset.id = acc.id || ''
		const isDefault = !!acc.isDefault

		const title = document.createElement('div')
		title.className = 'df-sa-row-title'
		title.textContent = isDefault ? t('dataforms', 'Default account') : (acc.name || t('dataforms', 'New account'))
		row.appendChild(title)

		const grid = document.createElement('div')
		grid.className = 'df-sa-grid'
		const field = (labelText, cls, type, value, ph) => {
			const lbl = document.createElement('label')
			lbl.textContent = labelText
			const inp = document.createElement('input')
			inp.type = type
			inp.className = cls
			inp.value = value || ''
			if (ph) {
				inp.placeholder = ph
			}
			grid.appendChild(lbl)
			grid.appendChild(inp)
			return inp
		}
		if (!isDefault) {
			field(t('dataforms', 'Name'), 'df-sa-name', 'text', acc.name, t('dataforms', 'e.g. Team bot'))
		}
		field(t('dataforms', 'Internal API URL'), 'df-sa-url', 'text', acc.url, 'http://localhost')
		field(t('dataforms', 'Username'), 'df-sa-user', 'text', acc.username, '')
		const pass = field(t('dataforms', 'App password'), 'df-sa-pass', 'password', '', acc.configured ? '••••••••  ' + t('dataforms', '(leave blank to keep)') : '')
		pass.autocomplete = 'new-password'
		row.appendChild(grid)

		const actions = document.createElement('div')
		actions.className = 'df-sa-actions'
		const button = (label, cls, primary) => {
			const b = document.createElement('button')
			b.textContent = label
			b.className = cls + (primary ? ' primary' : '')
			actions.appendChild(b)
			return b
		}
		const saveBtn = button(t('dataforms', 'Save'), 'df-sa-save', true)
		const testBtn = button(t('dataforms', 'Test'), 'df-sa-test', false)
		const removeBtn = button(isDefault ? t('dataforms', 'Clear') : t('dataforms', 'Remove'), 'df-sa-remove', false)
		const status = document.createElement('span')
		status.className = 'df-sa-status'
		status.textContent = acc.configured ? t('dataforms', 'Configured') : (acc.id || isDefault ? t('dataforms', 'Not configured') : '')
		status.dataset.kind = acc.configured ? 'ok' : ''
		actions.appendChild(status)
		row.appendChild(actions)

		const idOf = () => row.dataset.id
		const payload = () => ({
			id: idOf(),
			name: isDefault ? 'Default' : (row.querySelector('.df-sa-name')?.value || '').trim(),
			internalUrl: (row.querySelector('.df-sa-url').value || '').trim(),
			username: (row.querySelector('.df-sa-user').value || '').trim(),
			password: row.querySelector('.df-sa-pass').value,
		})

		saveBtn.addEventListener('click', async () => {
			saveBtn.disabled = true
			setRowStatus(row, t('dataforms', 'Saving…'), '')
			try {
				const r = (await axios.post(url('service-accounts'), payload(), cfg)).data.ocs.data
				render(r.accounts || [])
				initAutomationConfig() // Talk/Deck availability may have changed
			} catch (e) {
				setRowStatus(row, t('dataforms', 'Save failed'), 'err')
				saveBtn.disabled = false
			}
		})

		testBtn.addEventListener('click', async () => {
			testBtn.disabled = true
			setRowStatus(row, t('dataforms', 'Testing…'), '')
			try {
				const r = (await axios.post(url('service-accounts/test'), { id: idOf() }, cfg)).data.ocs.data
				setRowStatus(row, r.ok ? t('dataforms', 'Connection OK') : t('dataforms', 'Test failed (HTTP {status})', { status: r.status }), r.ok ? 'ok' : 'err')
			} catch (e) {
				setRowStatus(row, t('dataforms', 'Test failed'), 'err')
			} finally {
				testBtn.disabled = false
			}
		})

		removeBtn.addEventListener('click', async () => {
			if (!idOf()) {
				row.remove() // an unsaved new row
				return
			}
			if (!window.confirm(isDefault ? t('dataforms', 'Clear the default service account?') : t('dataforms', 'Remove this service account?'))) {
				return
			}
			try {
				const r = (await axios.delete(url('service-accounts'), { ...cfg, data: { id: idOf() } })).data.ocs.data
				render(r.accounts || [])
				initAutomationConfig()
			} catch (e) {
				setRowStatus(row, t('dataforms', 'Could not remove'), 'err')
			}
		})

		return row
	}

	const render = (accounts) => {
		list.textContent = ''
		for (const acc of accounts) {
			list.appendChild(buildRow(acc))
		}
	}

	addBtn?.addEventListener('click', () => {
		// A new, unsaved extra account (empty id → backend generates one on save).
		list.appendChild(buildRow({ id: '', name: '', url: '', username: '', configured: false, isDefault: false }))
	})

	axios.get(url('service-accounts'), cfg)
		.then((res) => render(res.data.ocs.data.accounts || []))
		.catch(() => { list.textContent = t('dataforms', 'Could not load service accounts') })
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
