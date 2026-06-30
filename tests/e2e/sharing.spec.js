// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for register sharing (docs/test-plan.md SHR group): a
 * manager opens the share dialog, searches for a user, grants a role, and sees
 * the share listed (SHR-01). The role↔permission mapping, the OR-union of
 * user+group permissions, and the server-side refusal of unauthorized
 * read/write/manage (SHR-10/11/12, the security-critical negatives) are proven
 * at the service / mapper / controller seams; this asserts the share UI wiring.
 *
 * Requires a second user "viewer" to exist (created by the test harness via occ).
 */

const USER = process.env.DATAFORMS_USER || 'admin'
const PASSWORD = process.env.DATAFORMS_PASSWORD || 'admin'
const SHAREE = process.env.DATAFORMS_SHAREE || 'viewer'

async function login(page) {
	await page.goto('/login')
	await page.fill('input[name="user"]', USER)
	await page.fill('input[name="password"]', PASSWORD)
	await page.click('button[type="submit"], input[type="submit"]')
	await page.waitForLoadState('networkidle')
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke grant a role to a user via the share dialog (SHR-01)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(`SHR ${stamp}`)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: `SHR ${stamp}` })).toBeVisible()

	// Open the share dialog (the manager-only Share button).
	await page.getByRole('button', { name: /^Share$/ }).click()
	const dialog = page.getByRole('dialog')
	await expect(dialog.getByText(USER)).toBeVisible() // the owner is listed

	// Search for the sharee and pick them.
	await dialog.getByRole('combobox').first().click()
	await dialog.getByRole('combobox').first().fill(SHAREE)
	await page.getByRole('option', { name: new RegExp(SHAREE, 'i') }).first().click()

	// Add at the default role; the new share appears in the list.
	await dialog.getByRole('button', { name: /^Add$/ }).click()
	await expect(dialog.getByText(SHAREE)).toBeVisible()
})
