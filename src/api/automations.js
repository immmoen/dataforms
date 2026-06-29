/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the workflow automations OCS API.
 */
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

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
	const d = await ocsGet('automation-actions')
	return { actions: d.actions ?? [], serviceAccounts: d.serviceAccounts ?? [] }
}

/**
 *
 * @param registerId
 */
export async function listAutomations(registerId) {
	return ocsGet(`registers/${registerId}/automations`)
}

/**
 * Recent automation runs for a register (newest first) — what fired and what failed.
 *
 * @param registerId
 */
export async function getAutomationLog(registerId) {
	return ocsGet(`registers/${registerId}/automation-log`)
}

/**
 *
 * @param registerId
 * @param data
 */
export async function createAutomation(registerId, data) {
	return ocsPost(`registers/${registerId}/automations`, data)
}

/**
 *
 * @param id
 * @param changes
 */
export async function updateAutomation(id, changes) {
	return ocsPut(`automations/${id}`, { changes })
}

/**
 *
 * @param id
 */
export async function deleteAutomation(id) {
	await ocsDelete(`automations/${id}`)
}
