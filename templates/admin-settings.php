<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var \OCP\IL10N $l */
/** @var array $_ */
$apiBase = $_['apiBase'];
$securityUrl = $_['securityUrl'];
$example = "curl -u \"USER:APP_PASSWORD\" \\\n"
	. "     -H \"OCS-APIRequest: true\" -H \"Accept: application/json\" \\\n"
	. '     "' . $apiBase . 'registers"';
?>
<div class="section" id="dataforms-api">
	<h2><?php p($l->t('DataForms API')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('DataForms is API-first: the whole app runs on this REST API, and any external system can use it too — for example to push records into a register from another tool. Access is internal and authenticated; every call respects the caller’s per-register permissions.')); ?>
	</p>

	<h3><?php p($l->t('Base URL')); ?></h3>
	<p><code class="df-code"><?php p($apiBase); ?></code></p>

	<h3><?php p($l->t('Authenticate')); ?></h3>
	<p><?php p($l->t('Use a Nextcloud app password (not your login password) with HTTP Basic auth, and send the header “OCS-APIRequest: true” on every request.')); ?></p>
	<ol class="df-steps">
		<li>
			<?php print_unescaped($l->t('Open %1$sSettings → Security%2$s and create a new app password.', ['<a href="' . \OCP\Util::sanitizeHTML($securityUrl) . '">', '</a>'])); ?>
		</li>
		<li><?php p($l->t('Send it as Basic auth: your username + the generated app password.')); ?></li>
		<li><?php p($l->t('Revoke it any time from the same screen — it never exposes your real password.')); ?></li>
	</ol>

	<h3><?php p($l->t('Example')); ?></h3>
	<pre class="df-pre"><?php p($example); ?></pre>

	<h3><?php p($l->t('Reference')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Full endpoint reference: docs/API.md in the app repository. A machine-readable OpenAPI description (registers + records) is in openapi.json and can be imported into Postman or Swagger.')); ?>
	</p>
</div>

<div class="section" id="dataforms-service-account">
	<h2><?php p($l->t('Cross-app provisioning')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Optional. Some automation actions — create a Talk room, set up a Deck board — call other Nextcloud apps from the background, where there is no logged-in user. They run as a dedicated service account you configure here. Its app password is stored encrypted and never shown again.')); ?>
	</p>
	<div class="df-sa-grid">
		<label for="df-sa-url"><?php p($l->t('Internal API URL')); ?></label>
		<input type="text" id="df-sa-url" placeholder="http://localhost">
		<label for="df-sa-user"><?php p($l->t('Service account username')); ?></label>
		<input type="text" id="df-sa-user" autocomplete="off">
		<label for="df-sa-pass"><?php p($l->t('App password')); ?></label>
		<input type="password" id="df-sa-pass" autocomplete="new-password">
	</div>
	<div class="df-sa-actions">
		<button id="df-sa-save" class="primary"><?php p($l->t('Save')); ?></button>
		<button id="df-sa-test"><?php p($l->t('Test connection')); ?></button>
		<button id="df-sa-clear"><?php p($l->t('Remove')); ?></button>
		<span id="df-sa-status" class="df-sa-status"></span>
	</div>
	<p class="settings-hint">
		<?php p($l->t('Create the app password under that account’s Settings → Security. The Internal API URL is this server’s own address as seen from itself — often http://localhost. Use “Test connection” to confirm.')); ?>
	</p>
</div>

<style>
#dataforms-api .df-code { background: var(--color-background-dark); padding: 2px 8px; border-radius: 6px; user-select: all; }
#dataforms-api .df-pre { background: var(--color-background-dark); padding: 12px 14px; border-radius: 8px; overflow-x: auto; white-space: pre; font-family: var(--font-face-monospace, monospace); }
#dataforms-api .df-steps { margin: 6px 0 10px 22px; list-style: decimal; }
#dataforms-api .df-steps li { margin-bottom: 4px; }
#dataforms-api h3 { margin-top: 18px; }

#dataforms-service-account .df-sa-grid { display: grid; grid-template-columns: max-content minmax(220px, 360px); gap: 8px 12px; align-items: center; margin: 10px 0; }
#dataforms-service-account .df-sa-grid input { width: 100%; }
#dataforms-service-account .df-sa-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
#dataforms-service-account .df-sa-status { margin-left: 8px; font-weight: 600; }
#dataforms-service-account .df-sa-status[data-kind="ok"] { color: var(--color-success, #2d7d33); }
#dataforms-service-account .df-sa-status[data-kind="err"] { color: var(--color-error, #c0392b); }
</style>
