/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the workflow automations OCS API.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)
const config = { timeout: 30000, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }
const unwrap = (r) => r.data.ocs.data

export const TRIGGERS = [
	{ id: 'create', label: 'When a record is created' },
	{ id: 'update', label: 'When a record is updated' },
	{ id: 'delete', label: 'When a record is deleted' },
]

export const ACTION_TYPES = [
	{ id: 'notify', label: 'Send a notification' },
	{ id: 'email', label: 'Send an email' },
	{ id: 'set_field', label: 'Set a field' },
	{ id: 'provision_folders', label: 'Create folders' },
	{ id: 'apply_template', label: 'Copy template files' },
	{ id: 'add_calendar_event', label: 'Add a calendar event' },
	{ id: 'create_talk_room', label: 'Create a Talk conversation' },
	{ id: 'create_deck_board', label: 'Create a Deck board' },
	{ id: 'webhook', label: 'Call a webhook' },
]

/**
 * The action type ids managers may currently pick (admin-enabled, with Talk/Deck
 * hidden until the service account is set up). Used to filter ACTION_TYPES.
 */
export async function getAvailableActions() {
	const d = unwrap(await axios.get(url('automation-actions'), config))
	return { actions: d.actions ?? [], serviceAccounts: d.serviceAccounts ?? [] }
}

/**
 *
 * @param registerId
 */
export async function listAutomations(registerId) {
	return unwrap(await axios.get(url(`registers/${registerId}/automations`), config))
}

/**
 * Recent automation runs for a register (newest first) — what fired and what failed.
 *
 * @param registerId
 */
export async function getAutomationLog(registerId) {
	return unwrap(await axios.get(url(`registers/${registerId}/automation-log`), config))
}

/**
 *
 * @param registerId
 * @param data
 */
export async function createAutomation(registerId, data) {
	return unwrap(await axios.post(url(`registers/${registerId}/automations`), data, config))
}

/**
 *
 * @param id
 * @param changes
 */
export async function updateAutomation(id, changes) {
	return unwrap(await axios.put(url(`automations/${id}`), { changes }, config))
}

/**
 *
 * @param id
 */
export async function deleteAutomation(id) {
	await axios.delete(url(`automations/${id}`), config)
}
