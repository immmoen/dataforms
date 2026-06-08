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
 * Folder names are templates with {machineName} placeholders (and {field|format}
 * date tokens) filled from the record's values, e.g. a "Client intake" form can
 * create Clients/{client_name}, Clients/{client_name}/Contracts, …
 *
 * Security properties:
 * - **Identity:** provisioning happens in the *record owner's* Files (resolved
 *   from the record), NOT whoever triggered the event. A deleted/disabled owner
 *   is a clean no-op.
 * - **Confinement:** every path segment is sanitised by {@see PathSafety} (no
 *   "/", "\", "..", control/bidi chars, Windows reserved names) and created one
 *   segment at a time, so a value can never escape its segment or the folder.
 * - **Bounded:** ≤ MAX_FOLDERS templates, ≤ PathSafety::MAX_DEPTH levels each,
 *   ≤ MAX_CREATED folders per fire.
 * - **Idempotent:** mkdir -p; re-firing reuses the existing tree.
 *
 * It is a deferred action (filesystem I/O) and runs off the request thread.
 *
 * action_config: { basePath?: string, folders: string[] }.
 */
class ProvisionFoldersAction implements IAction {

	private const MAX_FOLDERS = 50;   // templates per automation
	private const MAX_CREATED = 200;  // folders newly created per fire

	public function __construct(
		private IRootFolder $rootFolder,
		private RecordMapper $recordMapper,
		private IUserManager $userManager,
		private ValueInterpolator $interpolator,
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
			$interpolated = $this->interpolator->interpolate(
				$template,
				$context->values,
				static fn (string $s): string => PathSafety::pathSafeValue($s),
			);
			$segments = PathSafety::safeSegments($base . '/' . $interpolated);
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
