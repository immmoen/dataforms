// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for CSV import/export (docs/test-plan.md CSV group): import
 * a file whose headers match field labels/machine names, where each row runs the
 * normal validation pipeline and bad rows are reported (CSV-02/03/04); export the
 * table to a downloaded CSV (CSV-01); and download a header-only template
 * (CSV-09). The row cap and the no-automations-on-import rule are unit-proven
 * (ImportServiceTest); the BOM and formula-injection guard in ExportControllerTest.
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

async function addField(page, label, type) {
	await page.getByRole('button', { name: /Add field/i }).first().click()
	await page.getByLabel(/^Label$/i).fill(label)
	if (type) {
		await page.getByRole('combobox').first().click()
		await page.getByRole('option', { name: type, exact: true }).click()
	}
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText(label, { exact: true })).toBeVisible()
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke import a CSV with a bad row, then export and download a template (CSV-01..04, CSV-09)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(`CSV ${stamp}`)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: `CSV ${stamp}` })).toBeVisible()

	await page.getByRole('button', { name: /^Fields$/ }).click()
	await addField(page, 'Name')
	await addField(page, 'Qty', 'Number')

	await page.getByRole('button', { name: /^Records$/ }).click()

	// Open the import dialog (More → Import from CSV…).
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /Import from CSV/ }).click()

	// One valid row + one that fails the number validation (CSV-02/03/04). The
	// header "Qty" matches the field label; "Name" matches by machine name too.
	const csv = 'Name,Qty\nAlpha,5\nBeta,not-a-number\n'
	await page.locator('input[type="file"]').setInputFiles({
		name: 'data.csv', mimeType: 'text/csv', buffer: Buffer.from(csv),
	})

	// The per-row report: one imported, one failed with a reason.
	await expect(page.getByText(/Imported 1, 1 failed/)).toBeVisible()
	await expect(page.getByText(/Row 3/)).toBeVisible()

	// Close the dialog; the valid record is in the table.
	await page.keyboard.press('Escape')
	await expect(page.getByRole('cell', { name: 'Alpha' })).toBeVisible()

	// CSV-01 export: triggers a file download.
	await page.getByRole('button', { name: 'More' }).click()
	const exportDownload = page.waitForEvent('download')
	await page.getByRole('menuitem', { name: /Export to CSV/ }).click()
	const exported = await exportDownload
	expect(exported.suggestedFilename()).toMatch(/\.csv$/)

	// CSV-09 header-only template download (from the import dialog).
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /Import from CSV/ }).click()
	const tplDownload = page.waitForEvent('download')
	await page.getByRole('button', { name: /Download template/ }).click()
	const template = await tplDownload
	expect(template.suggestedFilename()).toMatch(/template\.csv$/)
})
