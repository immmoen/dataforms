<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Creates a folder tree in the **record owner's** Files when an automation fires
 * — the first of the "provisioning" actions that let DataForms drive intake →
 * workspace setup without an external flow runner.
 *
 * Folder names are templates with {machineName} placeholders filled from the
 * record's values, e.g. a "Client intake" form can create
 *   Clients/{client_name}, Clients/{client_name}/Contracts, …
 *
 * Security properties:
 * - **Identity:** provisioning happens in the *record owner's* (author's) Files,
 *   resolved from the record itself — NOT whoever triggered the event (a manager
 *   editing another user's record must not provision into the manager's home). A
 *   deleted or disabled owner is a clean no-op.
 * - **Confinement:** every path segment is sanitised (no "/", "\", "..", control/
 *   bidi chars, Windows reserved names) and created one segment at a time, so a
 *   field value can never escape its segment or the owner's folder.
 * - **Bounded:** at most MAX_FOLDERS templates, MAX_DEPTH levels each, and
 *   MAX_CREATED folders per fire — so one record event cannot fan out unbounded
 *   filesystem work or spam a quota.
 * - **Idempotent:** creation is mkdir -p; re-firing reuses the existing tree.
 *
 * It is a deferred action (filesystem I/O) and runs off the request thread.
 *
 * action_config: { basePath?: string, folders: string[] }.
 */
class ProvisionFoldersAction implements IAction {

	/** Caps that bound the filesystem fan-out of a single fire. */
	private const MAX_FOLDERS = 50;   // templates per automation
	private const MAX_DEPTH = 10;     // path segments per template
	private const MAX_CREATED = 200;  // folders newly created per fire

	/** Windows reserved device names, refused as folder names. */
	private const RESERVED = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i';

	/** Zero-width and bidirectional control characters (spoofing / confusables). */
	private const BIDI = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

	public function __construct(
		private IRootFolder $rootFolder,
		private RecordMapper $recordMapper,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function getType(): string {
		return 'provision_folders';
	}

	public function isDeferred(): bool {
		return true; // filesystem operations: run off the request thread
	}

	public function run(ActionContext $context): void {
		$templates = array_values(array_filter(
			array_map('strval', (array)($context->config['folders'] ?? [])),
			static fn ($s) => trim($s) !== ''
		));
		if ($templates === []) {
			return;
		}
		$templates = array_slice($templates, 0, self::MAX_FOLDERS);
		$base = (string)($context->config['basePath'] ?? '');

		// Act as the record OWNER (author), not the user who triggered the event.
		$owner = $this->recordMapper->findOwnerById($context->recordId);
		if ($owner === null || $owner === '') {
			return;
		}
		$user = $this->userManager->get($owner);
		if ($user === null || !$user->isEnabled()) {
			return; // deleted / disabled author → no-op
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($owner);
		} catch (\Throwable $e) {
			$this->logger->warning('Dataforms provision-folders: no user folder for ' . $owner, ['exception' => $e]);
			return;
		}

		$created = 0;
		foreach ($templates as $template) {
			if ($created >= self::MAX_CREATED) {
				$this->logger->warning('Dataforms provision-folders hit the per-run folder budget for record ' . $context->recordId);
				break;
			}
			$segments = $this->safeSegments($base . '/' . $this->interpolate($template, $context->values));
			if ($segments === []) {
				continue;
			}
			try {
				$created += $this->ensurePath($userFolder, $segments, self::MAX_CREATED - $created);
			} catch (\Throwable $e) {
				$this->logger->warning('Dataforms provision-folders: could not create ' . implode('/', $segments), ['exception' => $e]);
			}
		}
		if ($created > 0) {
			$this->logger->info('Dataforms provision-folders created ' . $created . ' folder(s) for record ' . $context->recordId);
		}
	}

	/**
	 * Replace {machineName} placeholders with the record's (path-safe) values.
	 *
	 * @param array<string,mixed> $values
	 */
	private function interpolate(string $template, array $values): string {
		return (string)preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/i', function (array $m) use ($values): string {
			return $this->safeValue($values[$m[1]] ?? '');
		}, $template);
	}

	/**
	 * Stringify a field value for use inside a single path segment, stripping path
	 * separators, control and bidi/zero-width characters so it cannot create extra
	 * segments, traverse out, or spoof a sibling name (defence in depth).
	 *
	 * @param mixed $value
	 */
	private function safeValue($value): string {
		if (is_bool($value)) {
			$value = $value ? 'yes' : 'no';
		} elseif (is_array($value)) {
			$parts = [];
			foreach ($value as $v) {
				$parts[] = is_array($v) ? (string)($v['label'] ?? $v['id'] ?? '') : (string)$v;
			}
			$value = implode('-', $parts);
		}
		$s = str_replace(['/', '\\', "\0"], ' ', (string)$value);
		$s = (string)preg_replace(self::BIDI, '', $s);
		$s = (string)preg_replace('/[\x00-\x1F]/', '', $s);
		return trim($s);
	}

	/**
	 * Split a relative path into safe segments: each is trimmed, bidi/illegal/
	 * reserved names are dropped, "." / ".." can never appear, and the depth is
	 * capped — so the result can only ever descend a bounded distance within the
	 * owner's folder.
	 *
	 * @return string[]
	 */
	private function safeSegments(string $path): array {
		$out = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $raw) {
			$seg = trim($raw);
			$seg = (string)preg_replace(self::BIDI, '', $seg);
			$seg = trim($seg, '.'); // forbid leading/trailing dots → blocks "." / ".."
			$seg = (string)preg_replace('#[\\\\/<>:"|?*\x00-\x1F]#', '', $seg);
			$seg = trim($seg);
			if ($seg === '' || preg_match(self::RESERVED, $seg)) {
				continue;
			}
			$out[] = mb_substr($seg, 0, 250);
			if (count($out) >= self::MAX_DEPTH) {
				break;
			}
		}
		return $out;
	}

	/**
	 * mkdir -p: descend/create each segment, reusing an existing folder. Returns
	 * the number of folders newly created (stopping at $budget). A non-folder node
	 * blocking the path stops the descent for that template.
	 *
	 * @param string[] $segments
	 */
	private function ensurePath(Folder $root, array $segments, int $budget): int {
		$current = $root;
		$created = 0;
		foreach ($segments as $seg) {
			if ($current->nodeExists($seg)) {
				$node = $current->get($seg);
				if (!$node instanceof Folder) {
					return $created; // a file occupies this name — do not clobber it
				}
				$current = $node;
			} else {
				if ($created >= $budget) {
					break;
				}
				$current = $current->newFolder($seg);
				$created++;
			}
		}
		return $created;
	}
}
