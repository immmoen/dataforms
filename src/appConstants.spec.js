/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect } from 'vitest'
import { APP_ID } from './appConstants.js'

describe('appConstants', () => {
	it('exposes the stable app id', () => {
		expect(APP_ID).toBe('dataforms')
	})
})
