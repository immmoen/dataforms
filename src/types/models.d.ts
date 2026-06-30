/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lightweight shapes for the domain objects the SPA receives from the OCS API.
 * Used via JSDoc (`@type {import('@/types/models').Field[]}`) to type component
 * props without a full TypeScript migration — only the fields the templates and
 * scripts actually read are declared.
 */

export interface Field {
	id: number
	machineName: string
	label: string
	type: string
	default?: unknown
	config?: Record<string, unknown>
	mandatory?: boolean
	unique?: boolean
	position?: number
}

export interface Rule {
	id: number
	effect: string
	target: string
	conditions?: unknown
	value?: unknown
	expression?: string
	validation?: unknown
	enabled?: boolean
}

export interface RecordRow {
	id: number
	seq?: number
	values: Record<string, unknown>
	[key: string]: unknown
}
