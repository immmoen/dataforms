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

<style>
#dataforms-api .df-code { background: var(--color-background-dark); padding: 2px 8px; border-radius: 6px; user-select: all; }
#dataforms-api .df-pre { background: var(--color-background-dark); padding: 12px 14px; border-radius: 8px; overflow-x: auto; white-space: pre; font-family: var(--font-face-monospace, monospace); }
#dataforms-api .df-steps { margin: 6px 0 10px 22px; list-style: decimal; }
#dataforms-api .df-steps li { margin-bottom: 4px; }
#dataforms-api h3 { margin-top: 18px; }
</style>
