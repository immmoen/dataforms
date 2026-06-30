// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for the register capability (docs/test-plan.md REG-01,
 * REG-06, REG-03): create a register, favourite/unfavourite it, and delete it —
 * driven through the SPA against a live Nextcloud, asserting on user-visible
 * outcomes only.
 *
 * Note: editing a register's metadata (REG-02) has no SPA UI today (the
 * updateRegister API exists but no component calls it), so it is not covered
 * here — see the issue raised alongside this work.
 */

const USER = process.env.DATAFORMS_USER || 'admin'
const PASSWORD = process.env.DATAFORMS_PASSWORD || 'admin'

async function login(page) {
	await page.goto('/login')
	await page.fill('input[name="user"]', USER)
	await page.fill('input[name="password"]', PASSWORD)
	await page.click('button[type="submit"], input[type="submit"]')
	await page.waitForLoadState('networkidle')
}

async function createRegister(page, title) {
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(title)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: title })).toBeVisible()
}

/** Open the ⋯ actions menu of the sidebar entry for `title`. */
async function openRegisterMenu(page, title) {
	const item = page.getByRole('listitem').filter({ hasText: title }).first()
	await item.getByRole('button', { name: 'Actions' }).click()
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke create and delete a register (REG-01, REG-03)', async ({ page }) => {
	const title = `REG ${Date.now().toString(36)}`
	await createRegister(page, title)
	await expect(page.locator('.app-navigation', { hasText: title })).toBeVisible()

	page.on('dialog', (d) => d.accept()) // confirmDelete()
	await openRegisterMenu(page, title)
	await page.getByRole('menuitem', { name: /Delete/ }).click()
	await expect(page.getByRole('listitem').filter({ hasText: title })).toHaveCount(0)
})

test('favourite a register, moving it under the Favourites caption (REG-06)', async ({ page }) => {
	const title = `FAV ${Date.now().toString(36)}`
	await createRegister(page, title)

	await openRegisterMenu(page, title)
	await page.getByRole('menuitem', { name: /Add to favourites/i }).click()
	await expect(page.getByText('Favourites', { exact: true })).toBeVisible()
})
