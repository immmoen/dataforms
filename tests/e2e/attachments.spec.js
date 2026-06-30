// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for file-attachment fields (docs/test-plan.md ATT group):
 * upload file(s) into the user's "Dataforms" Files folder, see them listed,
 * remove one, and persist the rest by reference (ATT-01/02/03; FLD-25). Files
 * are referenced by Nextcloud file id and never blobbed into the app DB — the
 * by-reference storage (ATT-04) is enforced at the upload controller, covered in
 * UploadControllerTest; this asserts the upload/remove/persist wiring end-to-end.
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

const file = (name, content) => ({ name, mimeType: 'text/plain', buffer: Buffer.from(content) })

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke upload, list, remove and persist file attachments (ATT-01, ATT-02, ATT-03, FLD-25)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(`ATT ${stamp}`)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: `ATT ${stamp}` })).toBeVisible()

	// Add a File attachment field.
	await page.getByRole('button', { name: /^Fields$/ }).click()
	await page.getByRole('button', { name: /Add field/i }).first().click()
	await page.getByLabel(/^Label$/i).fill('Attachment')
	await page.getByRole('combobox').first().click()
	// The option label wraps ("File att\nachment"), so match the intact prefix.
	await page.getByRole('option', { name: /File att/ }).click()
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText('Attachment', { exact: true })).toBeVisible()

	// New record: upload two files (ATT-01 + ATT-02) into the file field.
	await page.getByRole('button', { name: /^Records$/ }).click()
	await page.getByRole('button', { name: /New record/i }).first().click()
	const dialog = page.getByRole('dialog')
	await dialog.locator('input[type="file"]').setInputFiles([
		file(`alpha-${stamp}.txt`, 'a'),
		file(`beta-${stamp}.txt`, 'b'),
	])
	await expect(dialog.getByText(`alpha-${stamp}.txt`)).toBeVisible()
	await expect(dialog.getByText(`beta-${stamp}.txt`)).toBeVisible()

	// ATT-03: remove the first attachment.
	await dialog.getByRole('listitem').filter({ hasText: `alpha-${stamp}.txt` })
		.getByRole('button', { name: 'Remove file' }).click()
	await expect(dialog.getByText(`alpha-${stamp}.txt`)).toHaveCount(0)
	await expect(dialog.getByText(`beta-${stamp}.txt`)).toBeVisible()

	// Persist → the remaining attachment shows in the table (referenced by id).
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: new RegExp(`beta-${stamp}\\.txt`) })).toBeVisible()
})
