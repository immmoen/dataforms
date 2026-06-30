// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for relation fields (docs/test-plan.md REL group): build a
 * target register, link a record to it through a relation field, and confirm the
 * chosen display field renders for the linked record (REL-01, REL-03) — driven
 * through the SPA. The on-delete policies (block/cascade/clear) and the
 * cross-register read-permission guard (REL-07) are proven exhaustively at the
 * service seam (RecordRelationServiceTest / RecordServiceReadTest); this asserts
 * the picker + storage + label-resolution wiring through the whole stack.
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

/** Add a plain text field (already on the Fields tab). */
async function addTextField(page, label) {
	await page.getByRole('button', { name: /Add field/i }).first().click()
	await page.getByLabel(/^Label$/i).fill(label)
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText(label, { exact: true })).toBeVisible()
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto('/index.php/apps/dataforms/')
	await expect(page).toHaveTitle(/Dataforms/)
})

test('@smoke link a record via a relation field and show its display value (REL-01, REL-03)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	const companies = `Companies ${stamp}`
	const contacts = `Contacts ${stamp}`

	// Target register with a Name field and one record ("Acme").
	await createRegister(page, companies)
	await page.getByRole('button', { name: /^Fields$/ }).click()
	await addTextField(page, 'Name')
	await page.getByRole('button', { name: /^Records$/ }).click()
	await page.getByRole('button', { name: /New record/i }).first().click()
	await page.getByLabel('Name').fill('Acme')
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: 'Acme' })).toBeVisible()

	// Source register with a relation field pointing at Companies, display = Name.
	await createRegister(page, contacts)
	await page.getByRole('button', { name: /^Fields$/ }).click()
	await page.getByRole('button', { name: /Add field/i }).first().click()
	await page.getByLabel(/^Label$/i).fill('Employer')
	await page.getByRole('combobox').first().click() // type picker
	await page.getByRole('option', { name: /Relation/ }).click()
	// After picking relation, the config selects appear: [type, linked, display, onDelete].
	await page.getByRole('combobox').nth(1).click() // Linked register
	await page.getByRole('option', { name: companies, exact: true }).click()
	await page.getByRole('combobox').nth(2).click() // Display field
	await page.getByRole('option', { name: 'Name', exact: true }).click()
	await page.getByRole('button', { name: /Add field/i }).last().click()
	await expect(page.getByText('Employer', { exact: true })).toBeVisible()

	// New Contact: pick Acme through the relation picker, save.
	await page.getByRole('button', { name: /^Records$/ }).click()
	await page.getByRole('button', { name: /New record/i }).first().click()
	// The record form has a single field (the Employer relation) → one combobox.
	await page.getByRole('dialog').getByRole('combobox').first().click()
	await page.getByRole('option', { name: 'Acme' }).click()
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()

	// The linked record's display value (its Name) renders in the Contacts table.
	await expect(page.getByRole('cell', { name: 'Acme' })).toBeVisible()
})
