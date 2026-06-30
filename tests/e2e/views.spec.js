// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for views & browsing (docs/test-plan.md VW group): sort by
 * a column header (VW-09), full-text search (VW-01), a numeric filter with the
 * active-filter badge and Apply/Clear (VW-04/07/08), and saving a view that then
 * appears in the view selector (VW-12/16). The filter operators, the
 * owner-or-shared view query, and the column/view-state logic are covered at the
 * mapper/service/composable seams; this proves the browsing UI end-to-end.
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

async function addRecord(page, name, score) {
	await page.getByRole('button', { name: /New record/i }).first().click()
	const dialog = page.getByRole('dialog')
	await dialog.getByLabel('Name').fill(name)
	await dialog.getByLabel('Score').fill(String(score))
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name, exact: true })).toBeVisible()
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke search, sort, filter and save a view (VW-01, VW-04, VW-07, VW-08, VW-09, VW-12, VW-16)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(`VW ${stamp}`)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: `VW ${stamp}` })).toBeVisible()

	await page.getByRole('button', { name: /^Fields$/ }).click()
	await addField(page, 'Name')
	await addField(page, 'Score', 'Number')

	await page.getByRole('button', { name: /^Records$/ }).click()
	await addRecord(page, 'Alpha', 10)
	await addRecord(page, 'Beta', 20)
	await addRecord(page, 'Gamma', 30)

	// VW-09 sort: click the Score header → ascending, then again → descending.
	await page.getByRole('button', { name: 'Score' }).click()
	await expect(page.locator('tbody tr').first()).toContainText('Alpha') // 10 first (asc)
	await page.getByRole('button', { name: 'Score' }).click()
	await expect(page.locator('tbody tr').first()).toContainText('Gamma') // 30 first (desc)

	// VW-01 search: only the matching record remains.
	await page.getByRole('searchbox', { name: /Search records/i }).fill('Beta')
	await expect(page.getByRole('cell', { name: 'Beta', exact: true })).toBeVisible()
	await expect(page.getByRole('cell', { name: 'Alpha', exact: true })).toHaveCount(0)
	await page.getByRole('searchbox', { name: /Search records/i }).fill('')
	await expect(page.getByRole('cell', { name: 'Alpha', exact: true })).toBeVisible()

	// VW-07/08 filter: Score > 15 → Beta + Gamma; the Filter button shows a count.
	await page.getByRole('button', { name: /^Filter$/ }).click()
	const filterBar = page.locator('.filter-bar')
	await filterBar.getByRole('combobox').first().click() // field
	await page.getByRole('option', { name: 'Score', exact: true }).click()
	await filterBar.getByRole('combobox').nth(1).click() // operator
	await page.getByRole('option', { name: '>', exact: true }).click()
	await filterBar.locator('.f-val input').fill('15') // numeric value (a spinbutton)
	await filterBar.getByRole('button', { name: /^Apply$/ }).click()
	await expect(page.getByRole('cell', { name: 'Alpha', exact: true })).toHaveCount(0)
	await expect(page.getByRole('cell', { name: 'Beta', exact: true })).toBeVisible()
	await expect(page.getByRole('button', { name: /Filter \(1\)/ })).toBeVisible() // active-filter badge

	// VW-12 save the current view; VW-16 it then shows in the selector.
	await page.getByRole('button', { name: 'More' }).click()
	await page.getByRole('menuitem', { name: /Save current view/ }).click()
	const saveDialog = page.getByRole('dialog')
	await saveDialog.getByLabel('View name').fill(`Big scores ${stamp}`)
	await saveDialog.getByRole('button', { name: /^Save$/ }).click()
	await expect(page.getByText(`Big scores ${stamp}`)).toBeVisible()
})
