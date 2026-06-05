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

	const btn = document.createElement('a')
	btn.textContent = t('dataforms', 'Open form')
	btn.href = richObject.url
	btn.target = '_blank'
	btn.rel = 'noopener noreferrer'
	btn.setAttribute('style', css({
		flex: 'none',
		'text-decoration': 'none',
		padding: '6px 14px',
		'border-radius': 'var(--border-radius-element, 6px)',
		background: 'var(--color-primary-element)',
		color: 'var(--color-primary-element-text)',
		'font-weight': '500',
	}))

	card.append(icon, text, btn)
	el.append(card)
}, () => {}, {
	// A plain link card; no full interactive (inline-editing) mode.
	hasInteractiveView: false,
})
