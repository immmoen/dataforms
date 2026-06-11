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
	<div id="df-sa-list" class="df-sa-list"></div>
	<button id="df-sa-add"><?php p($l->t('Add another account')); ?></button>
	<p class="settings-hint">
		<?php p($l->t('Create the app password under that account’s Settings → Security. The Internal API URL is this server’s own address as seen from itself — often http://localhost. Use “Test” to confirm. Add more accounts to provision under different identities — an automation can then pick which account to use.')); ?>
	</p>
</div>

<div class="section" id="dataforms-automation">
	<h2><?php p($l->t('Automations')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Choose which automation actions managers can use, and tune the limits the engine applies. A disabled action disappears from the automation builder; the Talk and Deck actions appear only once the service account above is configured.')); ?>
	</p>

	<h3><?php p($l->t('Available actions')); ?></h3>
	<div id="df-auto-actions" class="df-auto-actions"></div>

	<h3><?php p($l->t('Limits & defaults')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Leave a field blank to use the default (shown as the placeholder).')); ?></p>
	<div class="df-auto-grid">
		<label for="df-auto-folders"><?php p($l->t('Max folders per “Create folders” action')); ?></label>
		<input type="number" id="df-auto-folders" min="1" data-limit="maxFolders">
		<label for="df-auto-created"><?php p($l->t('Max folders created per run')); ?></label>
		<input type="number" id="df-auto-created" min="1" data-limit="maxCreated">
		<label for="df-auto-files"><?php p($l->t('Max template files copied per run')); ?></label>
		<input type="number" id="df-auto-files" min="1" data-limit="maxTemplateFiles">
		<label for="df-auto-participants"><?php p($l->t('Max Talk participants added')); ?></label>
		<input type="number" id="df-auto-participants" min="1" data-limit="maxParticipants">
		<label for="df-auto-columns"><?php p($l->t('Max Deck columns')); ?></label>
		<input type="number" id="df-auto-columns" min="1" data-limit="maxDeckColumns">
		<label for="df-auto-duration"><?php p($l->t('Default calendar event length (minutes)')); ?></label>
		<input type="number" id="df-auto-duration" min="1" data-limit="calendarDefaultDuration">
		<label for="df-auto-timeout"><?php p($l->t('Outbound webhook timeout (seconds)')); ?></label>
		<input type="number" id="df-auto-timeout" min="1" data-limit="outboundTimeout">
		<label for="df-auto-deckcols"><?php p($l->t('Default Deck columns (comma-separated)')); ?></label>
		<input type="text" id="df-auto-deckcols">
	</div>
	<div class="df-auto-actions-row">
		<button id="df-auto-save" class="primary"><?php p($l->t('Save')); ?></button>
		<span id="df-auto-status" class="df-auto-status"></span>
	</div>
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
#dataforms-service-account .df-sa-list { display: flex; flex-direction: column; gap: 14px; margin: 10px 0; }
#dataforms-service-account .df-sa-account { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); padding: 12px 14px; }
#dataforms-service-account .df-sa-row-title { font-weight: 600; margin-bottom: 6px; }
#dataforms-service-account #df-sa-add { margin-top: 4px; }

#dataforms-automation .df-auto-actions { display: flex; flex-direction: column; gap: 6px; margin: 8px 0 4px; }
#dataforms-automation .df-auto-action { display: flex; align-items: center; gap: 8px; }
#dataforms-automation .df-auto-action label { cursor: pointer; }
#dataforms-automation .df-auto-note { color: var(--color-text-maxcontrast); font-size: 0.9em; }
#dataforms-automation .df-auto-grid { display: grid; grid-template-columns: max-content minmax(120px, 240px); gap: 8px 12px; align-items: center; margin: 10px 0; }
#dataforms-automation .df-auto-grid input { width: 100%; }
#dataforms-automation .df-auto-actions-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
#dataforms-automation .df-auto-status { margin-left: 8px; font-weight: 600; }
#dataforms-automation .df-auto-status[data-kind="ok"] { color: var(--color-success, #2d7d33); }
#dataforms-automation .df-auto-status[data-kind="err"] { color: var(--color-error, #c0392b); }
</style>
