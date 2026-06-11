<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Copies the contents of a template folder into a destination folder when an
 * automation fires — e.g. drop the standard meeting documents into the freshly
 * provisioned workspace. Both paths are {machineName}/{field|format} templates.
 *
 * Same hardening as the other provisioning actions: runs as the record OWNER,
 * deferred, every path segment sanitised by {@see PathSafety}, bounded number of
 * files, and idempotent (an existing target name is skipped, never overwritten).
 * Because it only reads/writes inside the owner's own Files, it can copy nothing
 * the owner couldn't copy by hand.
 *
 * action_config: { source: string, destination: string }.
 */
class ApplyTemplateAction implements IAction {

	public function __construct(
		private IRootFolder $rootFolder,
		private RecordMapper $recordMapper,
		private IUserManager $userManager,
		private ValueInterpolator $interpolator,
		private RelationResolver $relationResolver,
		private WorkflowSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	public function getType(): string {
		return 'apply_template';
	}

	public function isDeferred(): bool {
		// Inline so a record's workspace (folders + their template files) is ready
		// immediately on submit. Bounded by MAX_FILES; the slow/external actions
		// (webhook, email, Talk, Deck) stay deferred.
		return false;
	}

	public function run(ActionContext $context): void {
		$sourceTpl = trim((string)($context->config['source'] ?? ''));
		$destTpl = trim((string)($context->config['destination'] ?? ''));
		if ($sourceTpl === '' || $destTpl === '') {
			return;
		}

		$owner = $this->recordMapper->findOwnerById($context->recordId);
		if ($owner === null || $owner === '') {
			return;
		}
		$user = $this->userManager->get($owner);
		if ($user === null || !$user->isEnabled()) {
			return;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($owner);
		} catch (\Throwable $e) {
			$this->logger->warning('Dataforms apply-template: no user folder for ' . $owner, ['exception' => $e]);
			return;
		}

		$values = $this->relationResolver->enrich($owner, $context->registerId, $context->values);
		$transform = static fn (string $s): string => PathSafety::pathSafeValue($s);
		$srcSegments = PathSafety::safeSegments($this->interpolator->interpolate($sourceTpl, $values, $transform));
		$dstSegments = PathSafety::safeSegments($this->interpolator->interpolate($destTpl, $values, $transform));
		if ($srcSegments === [] || $dstSegments === []) {
			return;
		}

		$source = $this->resolveFolder($userFolder, $srcSegments);
		if ($source === null) {
			$this->logger->warning('Dataforms apply-template: source folder "' . implode('/', $srcSegments) . '" not found');
			return;
		}
		$dest = $this->ensureFolder($userFolder, $dstSegments);
		if ($dest === null) {
			$this->logger->warning('Dataforms apply-template: destination "' . implode('/', $dstSegments) . '" is blocked');
			return;
		}

		$copied = 0;
		$maxFiles = $this->settings->maxTemplateFiles();
		foreach ($source->getDirectoryListing() as $child) {
			if ($copied >= $maxFiles) {
				$this->logger->warning('Dataforms apply-template hit the per-run file budget for record ' . $context->recordId);
				break;
			}
			$name = $child->getName();
			if ($dest->nodeExists($name)) {
				continue; // idempotent: never overwrite an existing target
			}
			try {
				$child->copy($dest->getPath() . '/' . $name);
				$copied++;
			} catch (\Throwable $e) {
				$this->logger->warning('Dataforms apply-template: could not copy "' . $name . '"', ['exception' => $e]);
			}
		}
		if ($copied > 0) {
			$this->logger->info('Dataforms apply-template copied ' . $copied . ' item(s) for record ' . $context->recordId);
		}
	}

	/**
	 * Resolve an existing folder by descending sanitised segments; null if any
	 * segment is missing or is not a folder.
	 *
	 * @param string[] $segments
	 */
	private function resolveFolder(Folder $root, array $segments): ?Folder {
		$current = $root;
		foreach ($segments as $seg) {
			if (!$current->nodeExists($seg)) {
				return null;
			}
			$node = $current->get($seg);
			if (!$node instanceof Folder) {
				return null;
			}
			$current = $node;
		}
		return $current;
	}

	/**
	 * mkdir -p the destination, returning the final folder (or null if a non-folder
	 * node blocks the path).
	 *
	 * @param string[] $segments
	 */
	private function ensureFolder(Folder $root, array $segments): ?Folder {
		$current = $root;
		foreach ($segments as $seg) {
			if ($current->nodeExists($seg)) {
				$node = $current->get($seg);
				if (!$node instanceof Folder) {
					return null;
				}
				$current = $node;
			} else {
				$current = $current->newFolder($seg);
			}
		}
		return $current;
	}
}
