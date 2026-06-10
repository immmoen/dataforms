/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Registers the rich widget for inserted Dataforms form references. It renders
 * an interactive card (form name + register + "Open form" button) wherever
 * references are shown — Text, Talk, Collectives, Deck, … — built with plain DOM
 * and inline styles so it stays tiny and self-contained.
 */
import { registerWidget } from '@nextcloud/vue/components/NcRichText'
import { translate as t } from '@nextcloud/l10n'
import { imagePath } from '@nextcloud/router'

const css = (o) => Object.entries(o).map(([k, v]) => `${k}:${v}`).join(';')

registerWidget('dataforms_form', (el, { richObject, accessible }) => {
	el.style.width = '100%'

	if (!accessible) {
		el.textContent = t('dataforms', 'You don’t have access to this form.')
		el.style.color = 'var(--color-text-maxcontrast)'
		return
	}

	const card = document.createElement('div')
	card.setAttribute('style', css({
		display: 'flex',
		'align-items': 'center',
		gap: '12px',
		padding: '10px 12px',
		border: '1px solid var(--color-border)',
		'border-radius': 'var(--border-radius-large, 8px)',
		background: 'var(--color-main-background)',
		'max-width': '480px',
	}))

	const icon = document.createElement('span')
	const iconUrl = imagePath('dataforms', 'app.svg')
	icon.setAttribute('style', css({
		width: '28px',
		height: '28px',
		flex: 'none',
		'border-radius': '6px',
		background: 'var(--color-primary-element)',
		'-webkit-mask': `url(${iconUrl}) center / 18px no-repeat`,
		mask: `url(${iconUrl}) center / 18px no-repeat`,
	}))

	const text = document.createElement('span')
	text.setAttribute('style', css({ display: 'flex', 'flex-direction': 'column', 'min-width': '0', flex: '1' }))
	const title = document.createElement('span')
	title.textContent = richObject.name || t('dataforms', 'Form')
	title.setAttribute('style', css({ 'font-weight': '600', overflow: 'hidden', 'text-overflow': 'ellipsis', 'white-space': 'nowrap' }))
	const sub = document.createElement('span')
	sub.textContent = richObject.register ? t('dataforms', 'Form in {register}', { register: richObject.register }) : t('dataforms', 'Data-entry form')
	sub.setAttribute('style', css({ color: 'var(--color-text-maxcontrast)', 'font-size': '0.85em' }))
	text.append(title, sub)

	const actions = document.createElement('span')
	actions.setAttribute('style', css({ display: 'flex', gap: '8px', flex: 'none', 'align-items': 'center' }))

	// Primary: fill the form right here, without leaving the page.
	const fill = document.createElement('button')
	fill.type = 'button'
	fill.textContent = t('dataforms', 'Fill in')
	fill.setAttribute('style', css({
		border: 'none',
		cursor: 'pointer',
		padding: '6px 14px',
		'border-radius': 'var(--border-radius-element, 6px)',
		background: 'var(--color-primary-element)',
		color: 'var(--color-primary-element-text)',
		'font-weight': '500',
	}))
	fill.addEventListener('click', () => openInline(richObject, fill))

	// Secondary: open it in the app.
	const open = document.createElement('a')
	open.textContent = t('dataforms', 'Open')
	open.href = richObject.url
	open.target = '_blank'
	open.rel = 'noopener noreferrer'
	open.setAttribute('style', css({
		'text-decoration': 'none',
		padding: '6px 10px',
		color: 'var(--color-primary-element)',
		'font-weight': '500',
	}))

	actions.append(fill, open)
	card.append(icon, text, actions)
	el.append(card)
}, () => {}, {
	hasInteractiveView: false,
})

/**
 * Open the data-entry form over the current page, without navigating away. The
 * heavy form code (RecordForm + deps) is dynamically imported, so it only loads
 * when the user actually clicks "Fill in".
 * @param richObject
 * @param triggerBtn
 */
async function openInline(richObject, triggerBtn) {
	const prev = triggerBtn.textContent
	triggerBtn.disabled = true
	triggerBtn.textContent = t('dataforms', 'Loading…')
	let saved = false
	try {
		const [{ createApp }, { default: InlineForm }] = await Promise.all([
			import('vue'),
			import('./views/InlineForm.vue'),
		])
		const mount = document.createElement('div')
		document.body.appendChild(mount)
		const app = createApp(InlineForm, {
			registerId: Number(richObject.registerId),
			formId: richObject.id ? Number(richObject.id) : null,
			onSaved: () => {
				saved = true
				triggerBtn.textContent = '✓ ' + t('dataforms', 'Added')
				triggerBtn.style.background = 'var(--color-success, #2d7d46)'
			},
			onClose: () => {
				app.unmount()
				mount.remove()
				if (!saved) {
					triggerBtn.disabled = false
					triggerBtn.textContent = prev
				}
			},
		})
		app.mount(mount)
	} catch (e) {
		console.error(e)
		triggerBtn.disabled = false
		triggerBtn.textContent = prev
	}
}
