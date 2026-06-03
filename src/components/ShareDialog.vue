<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcDialog
		:name="t('dataforms', 'Share “{title}”', { title: register.title })"
		size="normal"
		@closing="$emit('close')">
		<div class="share-dialog">
			<p class="hint">
				{{ t('dataforms', 'Internal users and groups only — no public links or anonymous access.') }}
			</p>

			<div class="add-row">
				<NcTextField
					v-model="newWith"
					class="who"
					:label="newType === 'group' ? t('dataforms', 'Group ID') : t('dataforms', 'User ID')"
					@keydown.enter="add" />
				<NcSelect v-model="newType" :options="typeOptions" :reduce="(o) => o.id" label="label" :clearable="false" class="type" />
				<NcSelect v-model="newRole" :options="roles" :reduce="(o) => o.id" label="label" :clearable="false" class="role" />
				<NcButton type="primary" :disabled="saving || newWith.trim() === ''" @click="add">
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
						<NcSelect
							:model-value="roleOf(share.permissions)"
							:options="roles"
							:reduce="(o) => o.id"
							label="label"
							:clearable="false"
							class="role-sel"
							@update:model-value="changeRole(share, $event)" />
						<NcButton type="tertiary" @click="remove(share)">
							<template #icon><DeleteIcon :size="18" /></template>
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
import NcTextField from '@nextcloud/vue/components/NcTextField'

import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import { listShares, addShare, updateShare, removeShare, ROLES, roleOf, permissionsOf } from '../api/shares.js'

export default {
	name: 'ShareDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField, DeleteIcon },
	props: {
		register: { type: Object, required: true },
	},
	emits: ['close'],
	data() {
		return {
			shares: [],
			loading: true,
			saving: false,
			newWith: '',
			newType: 'user',
			newRole: 'read',
			roles: ROLES,
			typeOptions: [
				{ id: 'user', label: t('dataforms', 'User') },
				{ id: 'group', label: t('dataforms', 'Group') },
			],
		}
	},
	mounted() {
		this.load()
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
		async add() {
			if (this.newWith.trim() === '' || this.saving) return
			this.saving = true
			try {
				await addShare(this.register.id, {
					shareType: this.newType,
					shareWith: this.newWith.trim(),
					permissions: permissionsOf(this.newRole),
				})
				this.newWith = ''
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
.share-dialog { min-width: min(480px, 84vw); padding: 8px 2px; }
.hint { color: var(--color-text-maxcontrast); font-size: 0.85em; margin: 0 0 14px; }
.add-row { display: flex; gap: 8px; align-items: flex-end; margin-bottom: 16px; }
.add-row .who { flex: 1; }
.add-row .type { width: 110px; }
.add-row .role { width: 120px; }
.centered { margin: 30px auto; }
.share-list { display: flex; flex-direction: column; }
.share-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-top: 1px solid var(--color-border); }
.avatar { width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 11px; font-weight: 600; background: var(--color-primary-element); }
.avatar.group { background: var(--color-success, #2d7d46); }
.who-name { font-weight: 500; }
.type-tag { color: var(--color-text-maxcontrast); font-size: 0.78em; margin-left: 6px; }
.owner-tag { color: var(--color-text-maxcontrast); font-size: 0.85em; font-weight: 600; }
.spacer { flex: 1; }
.role-sel { width: 130px; }
</style>
