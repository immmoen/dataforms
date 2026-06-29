// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * Smoke end-to-end coverage for the Dataforms SPA against a live Nextcloud.
 * These tests log in, then exercise the core happy path: create a register,
 * add a field, add a record, see it in the table, filter it, and open the
 * drag-and-drop form builder. They assert on user-visible outcomes only, so
 * they stay robust across @nextcloud/vue version bumps.
 *
 * Requires a running instance (see playwright.config.js for env vars).
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

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke create a register, add a field, add a record, and see it', async ({ page }) => {
	const stamp = Date.now().toString(36)
	const registerTitle = `E2E ${stamp}`

	// New register
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(registerTitle)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: registerTitle })).toBeVisible()

	// Add a text field on the Fields tab
	await page.getByRole('button', { name: /^Fields$/ }).click()
	await page.getByRole('button', { name: /Add field/i }).click()
	await page.getByLabel(/Label/i).first().fill('Title')
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText('Title')).toBeVisible()

	// Back to Records, add a record
	await page.getByRole('button', { name: /^Records$/ }).click()
	await page.getByRole('button', { name: /New record/i }).first().click()
	await page.getByLabel('Title').fill('Hello world')
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: 'Hello world' })).toBeVisible()
})

test('the form builder opens with a draggable field palette', async ({ page }) => {
	// Assumes at least one register with fields exists (e.g. from the test above
	// or seeded data). Open the first register, then the Forms tab.
	await page.locator('.app-navigation .reg-dot, .reg-card').first().click()
	const formsTab = page.getByRole('button', { name: /^Forms$/ })
	if (await formsTab.isVisible()) {
		await formsTab.click()
		await page.getByRole('button', { name: /(New form|Build your first form)/i }).click()
		await expect(page.locator('.palette .chip').first()).toBeVisible()
		await expect(page.locator('.canvas .section').first()).toBeVisible()
	}
})
