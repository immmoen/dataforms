/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The register-shares API client: the role↔bitmask mapping (mirroring
 * lib/Db/Share.php) and the OCS transport for each operation.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'
import {
	ROLES, PERM_READ, PERM_WRITE, PERM_MANAGE, roleOf, permissionsOf,
	listShares, searchSharees, addShare, updateShare, removeShare,
} from './shares.js'

vi.mock('./ocs.js', () => ({
	ocsGet: vi.fn(() => Promise.resolve([])),
	ocsPost: vi.fn(() => Promise.resolve({ id: 1 })),
	ocsPut: vi.fn(() => Promise.resolve({ id: 1 })),
	ocsDelete: vi.fn(() => Promise.resolve()),
}))

describe('api/shares', () => {
	beforeEach(() => vi.clearAllMocks())

	it('maps a bitmask to the highest role it satisfies', () => {
		expect(roleOf(PERM_READ)).toBe('read')
		expect(roleOf(PERM_READ | PERM_WRITE)).toBe('write')
		expect(roleOf(PERM_READ | PERM_WRITE | PERM_MANAGE)).toBe('manage')
		expect(roleOf(0)).toBe('read') // default
	})

	it('maps a role to its (cumulative) bitmask', () => {
		expect(permissionsOf('read')).toBe(PERM_READ)
		expect(permissionsOf('write')).toBe(PERM_READ | PERM_WRITE)
		expect(permissionsOf('manage')).toBe(PERM_READ | PERM_WRITE | PERM_MANAGE)
		expect(permissionsOf('bogus')).toBe(PERM_READ) // unknown → read
		expect(ROLES.map((r) => r.id)).toEqual(['read', 'write', 'manage'])
	})

	it('routes each operation to the right OCS endpoint', async () => {
		await listShares(5)
		expect(ocsGet).toHaveBeenCalledWith('registers/5/shares')

		await searchSharees(5, 'bo')
		expect(ocsGet).toHaveBeenCalledWith('registers/5/sharees', { search: 'bo' })

		await addShare(5, { shareType: 'user', shareWith: 'bob', permissions: 3 })
		expect(ocsPost).toHaveBeenCalledWith('registers/5/shares', { shareType: 'user', shareWith: 'bob', permissions: 3 })

		await updateShare(2, 7)
		expect(ocsPut).toHaveBeenCalledWith('shares/2', { permissions: 7 })

		await removeShare(2)
		expect(ocsDelete).toHaveBeenCalledWith('shares/2')
	})
})
