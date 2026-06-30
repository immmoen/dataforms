// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'

/**
 * End-to-end coverage for the rule engine (docs/test-plan.md RUL group),
 * focused on the dual-engine guarantee: a COMPUTE rule evaluates live in the
 * browser as you type (RUL-05) and the server re-checks it on save so the
 * persisted value is authoritative (RUL-07). The five effects, every operator
 * and JS/PHP parity are covered exhaustively at the shared-fixture seam; this
 * proves the wiring end-to-end through the SPA.
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

/** Add a field of the given type via the schema editor (already on the Fields tab). */
async function addField(page, label, type) {
	await page.getByRole('button', { name: /Add field/i }).first().click()
	await page.getByLabel(/^Label$/i).fill(label)
	if (type !== 'Text') {
		// The type picker is the dialog's first combobox (NcSelect leaves it unnamed).
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

test('@smoke compute rule evaluates live and is re-checked on save (RUL-05, RUL-07)', async ({ page }) => {
	const stamp = Date.now().toString(36)
	await page.getByRole('button', { name: /New register/i }).first().click()
	await page.getByLabel(/Title/i).fill(`RUL ${stamp}`)
	await page.getByRole('button', { name: /^Create$/ }).click()
	await expect(page.getByRole('heading', { name: `RUL ${stamp}` })).toBeVisible()

	// Schema: two number inputs and a number target for the computed value.
	await page.getByRole('button', { name: /^Fields$/ }).click()
	await addField(page, 'Likelihood', 'Number')
	await addField(page, 'Impact', 'Number')
	await addField(page, 'Risk', 'Number')

	// Rule: Risk = likelihood * impact.
	await page.getByRole('button', { name: /^Rules$/ }).click()
	await page.getByRole('button', { name: /Add rule/i }).click()
	// Effect = Compute value (first combobox in the dialog).
	await page.getByRole('combobox').first().click()
	await page.getByRole('option', { name: 'Compute value', exact: true }).click()
	// Target = Risk (after choosing compute, the target is the second combobox).
	await page.getByRole('combobox').nth(1).click()
	await page.getByRole('option', { name: 'Risk', exact: true }).click()
	await page.getByLabel('Expression').fill('likelihood * impact')
	// The dialog's submit button shares the "Add rule" label with the toolbar one.
	await page.getByRole('dialog').getByRole('button', { name: /^Add rule$/ }).click()
	await expect(page.getByText('risk = likelihood * impact')).toBeVisible()

	// Records: entering the inputs computes Risk live (the JS engine).
	await page.getByRole('button', { name: /^Records$/ }).click()
	await page.getByRole('button', { name: /New record/i }).first().click()
	await page.getByLabel('Likelihood').fill('3')
	await page.getByLabel('Impact').fill('4')
	// The computed target renders the live value and is disabled (read-only).
	await expect(page.getByLabel('Risk')).toHaveValue('12')

	// Save → the server re-checks and persists the authoritative computed value.
	await page.getByRole('button', { name: /^(Save|Add)$/ }).click()
	await expect(page.getByRole('cell', { name: '12', exact: true })).toBeVisible()
})
