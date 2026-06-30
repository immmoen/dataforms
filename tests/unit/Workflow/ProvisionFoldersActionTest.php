<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ProvisionFoldersAction;
use OCA\Dataforms\Workflow\RelationResolver;
use OCA\Dataforms\Workflow\ValueInterpolator;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ProvisionFoldersAction (AUT-10): creates a folder tree in the record OWNER's
 * Files, mkdir -p (idempotent), skips a template whose placeholder is empty, and
 * no-ops for a missing/disabled owner. Path confinement is PathSafety's (tested
 * separately).
 */
class ProvisionFoldersActionTest extends TestCase {
	/** @var list<string> names passed to newFolder, in order */
	private array $created = [];

	private function settings(): WorkflowSettings {
		$cfg = $this->createMock(\OCP\IAppConfig::class);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $d);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $d);
		return new WorkflowSettings($cfg);
	}

	/** A folder mock: $existing names already present (reused), others are created. */
	private function folder(array $existing = []): Folder {
		$f = $this->createMock(Folder::class);
		$f->method('nodeExists')->willReturnCallback(static fn (string $n): bool => in_array($n, $existing, true));
		$f->method('get')->willReturnCallback(fn (string $n): Folder => $this->folder($existing));
		$f->method('newFolder')->willReturnCallback(function (string $n): Folder {
			$this->created[] = $n;
			return $this->folder();
		});
		return $f;
	}

	private function action(?IUser $owner, ?Folder $userFolder): ProvisionFoldersAction {
		$root = $this->createMock(IRootFolder::class);
		if ($userFolder !== null) {
			$root->method('getUserFolder')->willReturn($userFolder);
		} else {
			$root->method('getUserFolder')->willThrowException(new \RuntimeException('no folder'));
		}
		$records = $this->createMock(RecordMapper::class);
		$records->method('findOwnerById')->willReturn($owner === null ? null : 'alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($owner);
		$relations = $this->createMock(RelationResolver::class);
		$relations->method('enrich')->willReturnArgument(2);
		return new ProvisionFoldersAction($root, $records, $users, new ValueInterpolator(), $relations, $this->settings(), $this->createMock(LoggerInterface::class));
	}

	private function enabledUser(): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('isEnabled')->willReturn(true);
		return $u;
	}

	private function ctx(array $config, array $values = ['client' => 'Acme']): ActionContext {
		return new ActionContext(5, 9, 'alice', 'Folders', $values, $config);
	}

	public function testCreatesTheInterpolatedTree(): void {
		$action = $this->action($this->enabledUser(), $this->folder());
		$action->run($this->ctx(['folders' => ['Clients/{client}', 'Clients/{client}/Contracts']]));
		// mkdir -p: Clients, Acme, then (Clients exists this run? each folder() is fresh) ...
		$this->assertContains('Acme', $this->created);
		$this->assertContains('Contracts', $this->created);
	}

	public function testReusesExistingFoldersIdempotently(): void {
		// "Clients" already exists → only the new leaf is created.
		$action = $this->action($this->enabledUser(), $this->folder(['Clients']));
		$action->run($this->ctx(['folders' => ['Clients/{client}']]));
		$this->assertSame(['Acme'], $this->created);
	}

	public function testSkipsATemplateWhosePlaceholderIsEmpty(): void {
		$action = $this->action($this->enabledUser(), $this->folder());
		// {missing} resolves to empty → the whole template is skipped.
		$action->run($this->ctx(['folders' => ['Clients/{missing}/Docs']]));
		$this->assertSame([], $this->created);
	}

	public function testNoOpForMissingOrDisabledOwner(): void {
		$this->action(null, $this->folder())->run($this->ctx(['folders' => ['X']]));
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$this->action($disabled, $this->folder())->run($this->ctx(['folders' => ['X']]));
		$this->assertSame([], $this->created);
	}

	public function testNoOpWithoutTemplates(): void {
		$action = $this->action($this->enabledUser(), $this->folder());
		$action->run($this->ctx(['folders' => ['  ', '']]));
		$this->assertSame([], $this->created);
		$this->assertSame('provision_folders', $action->getType());
	}
}
