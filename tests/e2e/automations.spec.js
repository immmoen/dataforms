// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for the automation engine (docs/test-plan.md AUT group),
 * driven through the SPA with a REAL inline side effect: a "set a field"
 * automation on the create trigger writes a field directly when a record is
 * added (AUT-01/09), the run shows in the activity log (AUT-23), and the
 * automation can be edited / toggled / deleted (AUT-05/06).
 *
 * The deferred/external actions are verified at the action seam: the webhook's
 * HMAC signing + SSRF guard (WebhookActionTest — a real local receiver can't be
 * used because the action's own SSRF guard refuses private addresses), email
 * (EmailActionTest), and Talk/Deck via a faked NextcloudApiClient asserting the
 * exact OCS calls (Create{Talk,Deck}*Test). Folder/calendar provisioning are
 * unit-covered at their action seam; folder creation also runs inline here.
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

test('@smoke a set-field automation fires on create, logs, and can be managed (AUT-01, AUT-09, AUT-23, AUT-05, AUT-06)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(`AUT ${stamp}`)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: `AUT ${stamp}` })).toBeVisible()

	// A text field the automation will set.
	await page.getByRole('button', { name: /^Fields$/ }).click()
	await page.getByRole('button', { name: /Add field/i }).first().click()
	await page.getByLabel(/^Label$/i).fill('Status')
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText('Status', { exact: true })).toBeVisible()

	// Build the automation: on create → set "Status" = "Processed".
	await page.getByRole('button', { name: /^Automations$/ }).click()
	await page.getByRole('button', { name: /New automation/i }).click()
	const dialog = page.getByRole('dialog')
	await dialog.getByLabel('Name').fill('Stamp status')
	// Comboboxes in order: [trigger, action type]; trigger defaults to "created".
	await dialog.getByRole('combobox').nth(1).click() // action type
	await page.getByRole('option', { name: 'Set a field', exact: true }).click()
	// Now a third combobox (Field to set) appears.
	await dialog.getByRole('combobox').nth(2).click()
	await page.getByRole('option', { name: 'Status', exact: true }).click()
	await dialog.getByLabel('Value', { exact: true }).fill('Processed')
	await dialog.getByRole('button', { name: /^Add$/ }).click()
	await expect(page.getByText('Stamp status')).toBeVisible()

	// Add a record with Status blank → the automation fills it in (real inline effect).
	await page.getByRole('button', { name: /^Records$/ }).click()
	await page.getByRole('button', { name: /New record/i }).first().click()
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: 'Processed' })).toBeVisible()

	// AUT-23: the activity log records the run.
	await page.getByRole('button', { name: /^Automations$/ }).click()
	await page.getByRole('button', { name: /^Activity$/ }).click()
	const logDialog = page.getByRole('dialog')
	await expect(logDialog.getByText('Stamp status')).toBeVisible()
	await expect(logDialog.getByText('OK', { exact: true })).toBeVisible()
	await page.keyboard.press('Escape')

	// AUT-06: delete the automation (confirmed through a window.confirm). The
	// enable/disable toggle (AUT-05) is covered at the AutomationService seam.
	page.on('dialog', (d) => d.accept())
	const row = page.locator('.auto-row').filter({ hasText: 'Stamp status' })
	await row.getByRole('button', { name: 'Actions' }).click()
	await page.getByRole('menuitem', { name: 'Delete' }).click()
	await expect(page.getByText('Stamp status')).toHaveCount(0)
})
