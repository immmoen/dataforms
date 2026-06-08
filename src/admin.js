/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Admin → DataForms settings: wires the cross-app service-account form to the
 * OCS endpoints. Vanilla JS (no Vue) — the form is rendered server-side.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)
const cfg = { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }

document.addEventListener('DOMContentLoaded', () => {
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
		} catch (e) {
			setStatus(t('dataforms', 'Could not remove'), 'err')
		}
	})

	refresh()
})
