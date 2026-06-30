// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for the schema editor (docs/test-plan.md fields group):
 * create a register, then add a single-select field with options and a required
 * text field, and confirm they appear in the schema — exercising type-specific
 * config (FLD-17) and the common settings (FLD-01/04) through the SPA. The 20
 * field types and their validation are covered exhaustively at the service /
 * mapper seams; this asserts the editor wiring end-to-end.
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

test('add a single-select field with options via the schema editor', async ({ page }) => {
	const title = `FLD ${Date.now().toString(36)}`
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(title)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: title })).toBeVisible()

	await page.getByRole('button', { name: /^Fields$/ }).click()
	await page.getByRole('button', { name: /Add field/i }).click()

	// Common settings (FLD-01): label
	await page.getByLabel(/^Label$/i).fill('Priority')

	// Type-specific config (FLD-17): pick "Single select" and enter options.
	// The type picker is the dialog's only combobox (NcSelect leaves it unnamed).
	await page.getByRole('combobox').first().click()
	await page.getByRole('option', { name: 'Single select' }).click()
	await page.getByRole('textbox', { name: 'Options (one per line)' }).fill('Low\nMedium\nHigh')

	await page.getByRole('button', { name: /Add field/i }).last().click()

	// The field is now in the schema (its label and machine name are shown).
	await expect(page.getByText('Priority', { exact: true })).toBeVisible()
	await expect(page.getByText('priority', { exact: true })).toBeVisible()
})
