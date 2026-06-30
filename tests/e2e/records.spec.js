// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for the records capability (docs/test-plan.md REC group):
 * create a record via the blank form (REC-01), edit it via the full form
 * (REC-06), inline-edit a cell (REC-07) and cancel an inline edit with Esc
 * (REC-08), open the read-only detail (REC-10), and delete a record (REC-09) —
 * driven through the SPA against a live Nextcloud, asserting on user-visible
 * outcomes only. The validation, computed/auto and atomic-write behaviour is
 * covered exhaustively at the service and mapper seams; this proves the CRUD
 * wiring end-to-end.
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

/** Create a register with a single text field "Title", landing on its Records tab. */
async function registerWithTitleField(page, registerTitle) {
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(registerTitle)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: registerTitle })).toBeVisible()

	await page.getByRole('button', { name: /^Fields$/ }).click()
	await page.getByRole('button', { name: /Add field/i }).click()
	await page.getByLabel(/Label/i).first().fill('Title')
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText('Title', { exact: true })).toBeVisible()

	await page.getByRole('button', { name: /^Records$/ }).click()
}

async function addRecord(page, value) {
	await page.getByRole('button', { name: /New record/i }).first().click()
	await page.getByLabel('Title').fill(value)
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: value })).toBeVisible()
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke create, edit, and delete a record (REC-01, REC-06, REC-09)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await registerWithTitleField(page, `REC ${stamp}`)

	// REC-01: create via the blank form.
	await addRecord(page, 'First entry')

	// REC-06: edit via the full form (row ⋯ → Edit).
	await page.getByRole('row', { name: /First entry/ }).getByRole('button').last().click()
	await page.getByRole('menuitem', { name: 'Edit' }).click()
	const editInput = page.getByLabel('Title')
	await editInput.fill('First entry (edited)')
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: 'First entry (edited)' })).toBeVisible()

	// REC-09: delete via the row ⋯ → Delete (confirmed through a window.confirm).
	page.on('dialog', (dialog) => dialog.accept())
	await page.getByRole('row', { name: /First entry \(edited\)/ }).getByRole('button').last().click()
	await page.getByRole('menuitem', { name: 'Delete' }).click()
	await expect(page.getByRole('cell', { name: 'First entry (edited)' })).toHaveCount(0)
})

test('inline-edit a cell, and cancel an inline edit with Esc (REC-07, REC-08)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await registerWithTitleField(page, `REC ${stamp}`)
	await addRecord(page, 'Inline me')

	const cell = page.getByRole('cell', { name: 'Inline me' })

	// REC-08: Esc cancels — the original value is restored.
	await cell.dblclick()
	const inline = page.locator('.inline-input')
	await inline.fill('Should be discarded')
	await inline.press('Escape')
	await expect(page.getByRole('cell', { name: 'Inline me' })).toBeVisible()
	await expect(page.getByRole('cell', { name: 'Should be discarded' })).toHaveCount(0)

	// REC-07: double-click to edit, blur (clicking away) saves and renders live.
	// The Enter-to-save path has a known re-render glitch tracked in #25 (the value
	// still persists, but the cell shows a blank editor until reload), so this
	// asserts the blur path — also part of REC-07's "Enter/blur saves".
	await page.getByRole('cell', { name: 'Inline me' }).dblclick()
	const inline2 = page.locator('.inline-input')
	await inline2.fill('Inline saved')
	await inline2.blur()
	await expect(page.getByRole('cell', { name: 'Inline saved' })).toBeVisible()
})

test('open the read-only record detail (REC-10)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await registerWithTitleField(page, `REC ${stamp}`)
	await addRecord(page, 'Detail me')

	await page.getByRole('row', { name: /Detail me/ }).getByRole('button').last().click()
	await page.getByRole('menuitem', { name: 'View details' }).click()

	// The detail dialog (scoped, so it doesn't collide with the table cell) shows
	// the field label and its stored value.
	const dialog = page.getByRole('dialog')
	await expect(dialog.getByText('Detail me')).toBeVisible()
	await expect(dialog.getByText('Title', { exact: true })).toBeVisible()
})
