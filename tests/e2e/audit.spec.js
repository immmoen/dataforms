// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for per-record audit history (docs/test-plan.md AUD group):
 * a create and an update are recorded with author + change detail, and the
 * record detail's History panel shows the trail (AUD-01/02/04). Deletion is also
 * recorded (AUD-03), verified at the RecordService seam (RecordServiceDeleteTest)
 * since the record is gone from the UI after deletion.
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

test('@smoke create + update are recorded and shown in the History panel (AUD-01, AUD-02, AUD-04)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(`AUD ${stamp}`)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: `AUD ${stamp}` })).toBeVisible()

	await page.getByRole('button', { name: /^Fields$/ }).click()
	await page.getByRole('button', { name: /Add field/i }).first().click()
	await page.getByLabel(/^Label$/i).fill('Name')
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText('Name', { exact: true })).toBeVisible()

	// Create a record, then edit it — generating a create entry and an update entry.
	await page.getByRole('button', { name: /^Records$/ }).click()
	await page.getByRole('button', { name: /New record/i }).first().click()
	await page.getByLabel('Name').fill('First')
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: 'First' })).toBeVisible()

	await page.getByRole('row', { name: /First/ }).getByRole('button').last().click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	await page.getByLabel('Name').fill('Second')
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: 'Second' })).toBeVisible()

	// Open the read-only detail and expand the History panel.
	await page.getByRole('row', { name: /Second/ }).getByRole('button').last().click()
	await page.getByRole('menuitem', { name: 'View details' }).click()
	const dialog = page.getByRole('dialog')
	await dialog.getByRole('button', { name: /^History/ }).click()

	// The trail: the update (with its changed-field "Name" detail) and the
	// creation. The recorded author + timestamp are asserted at the mapper seam
	// (HistoryMapperTest); here the change detail proves the diff (AUD-02).
	await expect(dialog.getByText('Changed Name')).toBeVisible() // AUD-02 (diff)
	await expect(dialog.getByText('Created record')).toBeVisible() // AUD-01
	await expect(dialog.locator('.event-detail')).toContainText('Name') // the changed field
})
