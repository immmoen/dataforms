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
	canEdit?: boolean
	[key: string]: unknown
}

export interface Register {
	id: number
	title: string
	description?: string
	icon?: string
	color?: string
	isOwner?: boolean
	permissions?: number
	canWrite?: boolean
	canManage?: boolean
	favorite?: boolean
	recordCount?: number
}

export interface Form {
	id: number
	title: string
	definition: { sections: Array<{ title: string, fields: string[] }> }
}

export interface View {
	id: number
	title: string
	definition?: Record<string, unknown>
	shared?: boolean
	isOwner?: boolean
}

export interface Share {
	id: number
	shareType: number
	shareTypeName: string
	shareWith: string
	displayName?: string
	permissions: number
	isOwner?: boolean
}

export interface Automation {
	id: number
	name: string
	trigger: string
	actionType: string
	enabled?: boolean
	// Flexible config blobs (a condition tree / per-action settings).
	/* eslint-disable @typescript-eslint/no-explicit-any */
	condition?: any
	actionConfig?: any
	/* eslint-enable @typescript-eslint/no-explicit-any */
}

/** A {id,label} option for relation/select pickers. */
export interface Option {
	id: number | string
	label: string
}
