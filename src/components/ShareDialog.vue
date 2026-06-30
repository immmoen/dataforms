<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcDialog :name="t('dataforms', 'Share “{title}”', { title: register.title })"
		size="normal"
		@closing="$emit('close')">
		<div class="share-dialog">
			<p class="hint">
				{{ t('dataforms', 'Internal users and groups only — no public links or anonymous access.') }}
			</p>

			<div class="add-row">
				<NcSelect class="who"
					:model-value="selectedSharee"
					:options="shareeOptions"
					:loading="searching"
					:filterable="false"
					:clearable="true"
					label="label"
					:placeholder="t('dataforms', 'Search for a user or group…')"
					@search="onShareeSearch"
					@update:model-value="selectedSharee = $event">
					<template #option="opt">
						<span class="opt">
							<span class="opt-avatar" :class="opt.type">{{ opt.label.slice(0, 2).toUpperCase() }}</span>
							<span class="opt-label">{{ opt.label }}</span>
							<span class="opt-tag">{{ opt.type === 'group' ? t('dataforms', 'group') : opt.sub }}</span>
						</span>
					</template>
					<template #no-options>
						{{ lastQuery.length < 1 ? t('dataforms', 'Type a name to search…') : t('dataforms', 'No matching users or groups') }}
					</template>
				</NcSelect>
				<NcSelect v-model="newRole"
					:options="roles"
					:reduce="(o) => o.id"
					label="label"
					:clearable="false"
					class="role" />
				<NcButton class="add-btn"
					variant="primary"
					:disabled="saving || !selectedSharee"
					@click="add">
					{{ t('dataforms', 'Add') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loading" class="centered" :size="28" />
			<ul v-else class="share-list">
				<li v-for="share in shares" :key="share.id + '-' + share.shareWith" class="share-row">
					<span class="avatar" :class="share.shareTypeName === 'group' ? 'group' : 'user'">
						{{ share.shareWith.slice(0, 2).toUpperCase() }}
					</span>
					<span class="who-name">
						{{ share.shareWith }}
						<span class="type-tag">{{ share.shareTypeName === 'group' ? t('dataforms', 'group') : t('dataforms', 'user') }}</span>
					</span>
					<span class="spacer" />
					<template v-if="share.isOwner">
						<span class="owner-tag">{{ t('dataforms', 'Owner') }}</span>
					</template>
					<template v-else>
						<NcSelect :model-value="roleOf(share.permissions)"
							:options="roles"
							:reduce="(o) => o.id"
							label="label"
							:clearable="false"
							class="role-sel"
							@update:model-value="changeRole(share, $event)" />
						<NcButton variant="tertiary" @click="remove(share)">
							<template #icon>
								<DeleteIcon :size="18" />
							</template>
						</NcButton>
					</template>
				</li>
			</ul>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import { listShares, searchSharees, addShare, updateShare, removeShare, ROLES, roleOf, permissionsOf } from '../api/shares.js'

export default {
	name: 'ShareDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcSelect, DeleteIcon },
	props: {
		register: { type: Object, required: true },
	},
	emits: ['close'],
	data() {
		return {
			shares: [],
			loading: true,
			saving: false,
			selectedSharee: null, // { id, label, sub, type } picked from search
			shareeOptions: [],
			searching: false,
			searchTimer: null,
			lastQuery: '',
			newRole: 'read',
			roles: ROLES,
		}
	},
	mounted() {
		this.load()
	},
	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},
	methods: {
		t,
		roleOf,
		async load() {
			this.loading = true
			try {
				this.shares = await listShares(this.register.id)
			} catch (e) {
				showError(t('dataforms', 'Could not load shares'))
				console.error(e)
			} finally {
				this.loading = false
			}
		},
		onShareeSearch(query) {
			this.lastQuery = query
			clearTimeout(this.searchTimer)
			if (query.trim() === '') {
				this.shareeOptions = []
				return
			}
			this.searchTimer = setTimeout(async () => {
				this.searching = true
				try {
					this.shareeOptions = await searchSharees(this.register.id, query.trim())
				} catch (e) {
					console.error(e)
				} finally {
					this.searching = false
				}
			}, 250)
		},
		async add() {
			if (!this.selectedSharee || this.saving) return
			this.saving = true
			try {
				await addShare(this.register.id, {
					shareType: this.selectedSharee.type,
					shareWith: this.selectedSharee.id,
					permissions: permissionsOf(this.newRole),
				})
				this.selectedSharee = null
				this.shareeOptions = []
				this.lastQuery = ''
				await this.load()
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.message ?? t('dataforms', 'Could not add the share'))
				console.error(e)
			} finally {
				this.saving = false
			}
		},
		async changeRole(share, roleId) {
			try {
				await updateShare(share.id, permissionsOf(roleId))
				await this.load()
			} catch (e) {
				showError(t('dataforms', 'Could not update the share'))
				console.error(e)
			}
		},
		async remove(share) {
			try {
				await removeShare(share.id)
				this.shares = this.shares.filter((s) => s.id !== share.id)
			} catch (e) {
				showError(t('dataforms', 'Could not remove the share'))
				console.error(e)
			}
		},
	},
}
</script>

<style scoped>
.share-dialog { min-width: min(480px, 86vw); max-width: 100%; padding: 8px 2px; box-sizing: border-box; }

.hint { color: var(--color-text-maxcontrast); font-size: 0.85em; margin: 0 0 14px; }

/* Deterministic 2x2 layout so it fits any dialog width:
     [ type ][ who          ]
     [ role ][        Add ]
   (NcSelect ships a 260px min-width, so we let it shrink to its cell). */
.add-row {
	display: grid;
	grid-template-columns: 1fr auto;
	grid-template-areas:
		"who who"
		"role add";
	gap: 8px;
	align-items: end;
	margin-bottom: 16px;
}

.add-row .who { grid-area: who; }

.add-row .role { grid-area: role; }

.add-row .add-btn { grid-area: add; justify-self: end; }

/* search result rows */
.opt { display: flex; align-items: center; gap: 8px; width: 100%; }

.opt-avatar { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 10px; font-weight: 600; flex: none; background: var(--color-primary-element); }

.opt-avatar.group { background: var(--color-success, #2d7d46); }

.opt-label { font-weight: 500; }

.opt-tag { margin-inline-start: auto; color: var(--color-text-maxcontrast); font-size: 0.78em; }

/* Allow the @nextcloud/vue selects to shrink to their container. */
.share-dialog :deep(.v-select) { min-width: 0; }

.share-dialog :deep(.input-field) { min-width: 0; }

.centered { margin: 30px auto; }

.share-list { display: flex; flex-direction: column; }

.share-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--color-border); min-width: 0; }

.avatar { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 11px; font-weight: 600; background: var(--color-primary-element); flex: none; }

.avatar.group { background: var(--color-success, #2d7d46); }

.who-name { font-weight: 500; min-width: 0; overflow: hidden; text-overflow: ellipsis; }

.type-tag { color: var(--color-text-maxcontrast); font-size: 0.78em; margin-inline-start: 6px; }

.owner-tag { color: var(--color-text-maxcontrast); font-size: 0.85em; font-weight: 600; }

.spacer { flex: 1; }

.role-sel { width: 130px; flex: none; }
</style>
