/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Characterization tests for the resource clients. Each module is a thin
 * wrapper over the OCS transport seam (ocs.js); these tests pin the exact
 * method, path and payload every function produces, so the factory refactor
 * is provably behaviour-preserving.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'
import axios from '@nextcloud/axios'
import * as registers from './registers.js'
import * as fields from './fields.js'
import * as forms from './forms.js'
import * as views from './views.js'
import * as rules from './rules.js'
import * as automations from './automations.js'
import * as shares from './shares.js'
import * as records from './records.js'

const P = '/ocs/v2.php/apps/dataforms/api/v1/'
const CONFIG = { timeout: 30000, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }
const envelope = (data) => ({ data: { ocs: { data } } })

beforeEach(() => {
	vi.clearAllMocks()
	axios.get.mockResolvedValue(envelope(null))
	axios.post.mockResolvedValue(envelope(null))
	axios.put.mockResolvedValue(envelope(null))
	axios.delete.mockResolvedValue(envelope(null))
})

describe('registers client', () => {
	it('lists, creates, updates, deletes and favourites', async () => {
		axios.get.mockResolvedValueOnce(envelope([{ id: 1 }]))
		expect(await registers.listRegisters()).toEqual([{ id: 1 }])
		expect(axios.get).toHaveBeenCalledWith(P + 'registers', CONFIG)

		await registers.createRegister({ title: 'T' })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers', { title: 'T' }, CONFIG)

		await registers.updateRegister(5, { color: '#fff' })
		expect(axios.put).toHaveBeenCalledWith(P + 'registers/5', { color: '#fff' }, CONFIG)

		await registers.deleteRegister(5)
		expect(axios.delete).toHaveBeenCalledWith(P + 'registers/5', CONFIG)

		await registers.favoriteRegister(5, true)
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/5/favorite', { favorite: true }, CONFIG)
	})

	it('exposes icon and colour presets', () => {
		expect(registers.REGISTER_ICONS).toContain('table')
		expect(registers.REGISTER_COLORS[0]).toBe('#0082c9')
	})
})

describe('fields client', () => {
	it('lists, creates, updates, deletes and reorders', async () => {
		await fields.listFields(2)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/2/fields', CONFIG)

		await fields.createField(2, { label: 'A', type: 'text' })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/2/fields', { label: 'A', type: 'text' }, CONFIG)

		await fields.updateField(7, { label: 'B' })
		expect(axios.put).toHaveBeenCalledWith(P + 'fields/7', { label: 'B' }, CONFIG)

		await fields.deleteField(7)
		expect(axios.delete).toHaveBeenCalledWith(P + 'fields/7', CONFIG)

		await fields.reorderFields(2, [3, 1, 2])
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/2/fields/reorder', { order: [3, 1, 2] }, CONFIG)
	})

	it('covers all 20 field types and the auto kinds', () => {
		expect(fields.FIELD_TYPES).toHaveLength(20)
		expect(fields.AUTO_KINDS.map((k) => k.id)).toEqual(['sequence', 'created_at', 'updated_at', 'created_by'])
	})

	it('typeLabel resolves a label and falls back to the id', () => {
		expect(fields.typeLabel('select')).toBe('Single select')
		expect(fields.typeLabel('nope')).toBe('nope')
	})

	it('groupForOption groups by a pattern, returns Other on no match, empty on no pattern', () => {
		expect(fields.groupForOption('Art 6(1)(a)', '^[A-Za-z.]+\\s*\\d+')).toBe('Art 6')
		expect(fields.groupForOption('plain', '^\\d+')).toBe('Other')
		expect(fields.groupForOption('whatever', '')).toBe('')
		expect(fields.groupForOption('x', '(')).toBe('') // invalid regex → caught
	})
})

describe('forms client', () => {
	it('lists, creates, updates and deletes', async () => {
		await forms.listForms(3)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/3/forms', CONFIG)
		await forms.createForm(3, { title: 'F' })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/3/forms', { title: 'F' }, CONFIG)
		await forms.updateForm(8, { title: 'G' })
		expect(axios.put).toHaveBeenCalledWith(P + 'forms/8', { title: 'G' }, CONFIG)
		await forms.deleteForm(8)
		expect(axios.delete).toHaveBeenCalledWith(P + 'forms/8', CONFIG)
	})
})

describe('views client', () => {
	it('lists, creates, updates and deletes', async () => {
		await views.listViews(4)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/4/views', CONFIG)
		await views.createView(4, { title: 'V' })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/4/views', { title: 'V' }, CONFIG)
		await views.updateView(9, { shared: true })
		expect(axios.put).toHaveBeenCalledWith(P + 'views/9', { shared: true }, CONFIG)
		await views.deleteView(9)
		expect(axios.delete).toHaveBeenCalledWith(P + 'views/9', CONFIG)
	})
})

describe('rules client', () => {
	it('lists, creates, updates and deletes', async () => {
		await rules.listRules(5)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/5/rules', CONFIG)
		await rules.createRule(5, { effect: 'show' })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/5/rules', { effect: 'show' }, CONFIG)
		await rules.updateRule(10, { enabled: false })
		expect(axios.put).toHaveBeenCalledWith(P + 'rules/10', { enabled: false }, CONFIG)
		await rules.deleteRule(10)
		expect(axios.delete).toHaveBeenCalledWith(P + 'rules/10', CONFIG)
	})

	it('exposes the five effects and operator sets', () => {
		expect(rules.RULE_EFFECTS.map((e) => e.id)).toEqual(['show', 'require', 'set_value', 'compute', 'validate'])
		expect(rules.CONDITION_OPS.length).toBeGreaterThan(rules.FILTER_OPS.length)
	})
})

describe('automations client', () => {
	it('reads available actions with defaults', async () => {
		axios.get.mockResolvedValueOnce(envelope({ actions: ['notify'], serviceAccounts: [] }))
		expect(await automations.getAvailableActions()).toEqual({ actions: ['notify'], serviceAccounts: [] })
		expect(axios.get).toHaveBeenCalledWith(P + 'automation-actions', CONFIG)
	})

	it('defaults actions/serviceAccounts to empty arrays when absent', async () => {
		axios.get.mockResolvedValueOnce(envelope({}))
		expect(await automations.getAvailableActions()).toEqual({ actions: [], serviceAccounts: [] })
	})

	it('lists, logs, creates, updates and deletes', async () => {
		await automations.listAutomations(6)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/6/automations', CONFIG)
		await automations.getAutomationLog(6)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/6/automation-log', CONFIG)
		await automations.createAutomation(6, { trigger: 'create' })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/6/automations', { trigger: 'create' }, CONFIG)
		await automations.updateAutomation(11, { enabled: true })
		expect(axios.put).toHaveBeenCalledWith(P + 'automations/11', { changes: { enabled: true } }, CONFIG)
		await automations.deleteAutomation(11)
		expect(axios.delete).toHaveBeenCalledWith(P + 'automations/11', CONFIG)
	})

	it('exposes the three triggers and nine action types', () => {
		expect(automations.TRIGGERS).toHaveLength(3)
		expect(automations.ACTION_TYPES).toHaveLength(9)
	})
})

describe('shares client', () => {
	it('lists, searches, adds, updates and removes', async () => {
		await shares.listShares(7)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/7/shares', CONFIG)
		await shares.searchSharees(7, 'alice')
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/7/sharees', { ...CONFIG, params: { search: 'alice' } })
		await shares.addShare(7, { shareType: 'user', shareWith: 'bob', permissions: 1 })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/7/shares', { shareType: 'user', shareWith: 'bob', permissions: 1 }, CONFIG)
		await shares.updateShare(12, 3)
		expect(axios.put).toHaveBeenCalledWith(P + 'shares/12', { permissions: 3 }, CONFIG)
		await shares.removeShare(12)
		expect(axios.delete).toHaveBeenCalledWith(P + 'shares/12', CONFIG)
	})

	it('maps permission bitmasks to roles and back', () => {
		expect(shares.roleOf(shares.PERM_READ | shares.PERM_WRITE | shares.PERM_MANAGE)).toBe('manage')
		expect(shares.roleOf(shares.PERM_READ | shares.PERM_WRITE)).toBe('write')
		expect(shares.roleOf(shares.PERM_READ)).toBe('read')
		expect(shares.permissionsOf('write')).toBe(shares.PERM_READ | shares.PERM_WRITE)
		expect(shares.permissionsOf('unknown')).toBe(shares.PERM_READ)
	})
})

describe('records client', () => {
	it('lists, creates, updates, deletes, reads history and options, resolves files, imports', async () => {
		await records.listRecords(8, { limit: 5 })
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/8/records', { ...CONFIG, params: { limit: 5 } })

		await records.listRecords(8)
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/8/records', { ...CONFIG, params: {} })

		await records.createRecord(8, { title: 'x' })
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/8/records', { values: { title: 'x' } }, CONFIG)

		await records.updateRecord(13, { title: 'y' })
		expect(axios.put).toHaveBeenCalledWith(P + 'records/13', { values: { title: 'y' } }, CONFIG)

		await records.deleteRecord(13)
		expect(axios.delete).toHaveBeenCalledWith(P + 'records/13', CONFIG)

		await records.listHistory(13)
		expect(axios.get).toHaveBeenCalledWith(P + 'records/13/history', CONFIG)

		await records.listOptions(14, { display: 'name' })
		expect(axios.get).toHaveBeenCalledWith(P + 'registers/14/options', { ...CONFIG, params: { display: 'name' } })

		await records.resolveFile('/a/b.txt')
		expect(axios.get).toHaveBeenCalledWith(P + 'files/resolve', { ...CONFIG, params: { path: '/a/b.txt' } })

		await records.importCsv(8, 'a,b\n1,2')
		expect(axios.post).toHaveBeenCalledWith(P + 'registers/8/import', { csv: 'a,b\n1,2' }, CONFIG)
	})

	it('uploads a local file via the normal app route and returns the raw body', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 99, name: 'f.txt' } })
		const result = await records.uploadLocalFile('file-contents')
		expect(result).toEqual({ id: 99, name: 'f.txt' })
		const [calledUrl, body, opts] = axios.post.mock.calls.at(-1)
		expect(calledUrl).toBe('//apps/dataforms/upload')
		expect(body).toBeInstanceOf(FormData)
		expect(opts).toEqual({ timeout: 120000 })
	})

	it('builds the CSV export URL via the normal route', () => {
		expect(records.csvExportUrl(8)).toBe('//apps/dataforms/registers/8/export/csv')
	})
})
