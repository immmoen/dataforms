<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\ApplyTemplateAction;
use OCA\Dataforms\Workflow\RelationResolver;
use OCA\Dataforms\Workflow\ValueInterpolator;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ApplyTemplateAction (AUT-11): copies the files of a template folder into a
 * destination in the record owner's Files, idempotent (never overwrites an
 * existing target), and a no-op when the source is missing.
 */
class ApplyTemplateActionTest extends TestCase {
	/** @var list<string> copy targets */
	private array $copied = [];

	private function settings(): WorkflowSettings {
		$cfg = $this->createMock(\OCP\IAppConfig::class);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $d);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $d);
		return new WorkflowSettings($cfg);
	}

	private function file(string $name): File {
		$f = $this->createMock(File::class);
		$f->method('getName')->willReturn($name);
		$f->method('copy')->willReturnCallback(function (string $target) {
			$this->copied[] = $target;
			return $this->createMock(File::class);
		});
		return $f;
	}

	private function destFolder(array $existing = []): Folder {
		$d = $this->createMock(Folder::class);
		$d->method('getPath')->willReturn('/Output');
		$d->method('nodeExists')->willReturnCallback(static fn (string $n): bool => in_array($n, $existing, true));
		return $d;
	}

	/**
	 * userFolder: resolves "Templates" → $src; creates "Output" → $dest.
	 */
	private function action(Folder $src, Folder $dest): ApplyTemplateAction {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturnCallback(static fn (string $n): bool => $n === 'Templates');
		$userFolder->method('get')->willReturn($src);
		$userFolder->method('newFolder')->willReturn($dest);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$records = $this->createMock(RecordMapper::class);
		$records->method('findOwnerById')->willReturn('alice');
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$relations = $this->createMock(RelationResolver::class);
		$relations->method('enrich')->willReturnArgument(2);
		return new ApplyTemplateAction($root, $records, $users, new ValueInterpolator(), $relations, $this->settings(), $this->createMock(LoggerInterface::class));
	}

	private function srcFolder(array $files): Folder {
		$f = $this->createMock(Folder::class);
		$f->method('getDirectoryListing')->willReturn($files);
		return $f;
	}

	private function ctx(): ActionContext {
		return new ActionContext(5, 9, 'alice', 'Tmpl', [], ['source' => 'Templates', 'destination' => 'Output']);
	}

	public function testCopiesTemplateFilesIntoTheDestination(): void {
		$this->action($this->srcFolder([$this->file('a.txt'), $this->file('b.txt')]), $this->destFolder())->run($this->ctx());
		$this->assertSame(['/Output/a.txt', '/Output/b.txt'], $this->copied);
	}

	public function testIsIdempotentSkippingExistingTargets(): void {
		// dest already has a.txt → only b.txt copied.
		$this->action($this->srcFolder([$this->file('a.txt'), $this->file('b.txt')]), $this->destFolder(['a.txt']))->run($this->ctx());
		$this->assertSame(['/Output/b.txt'], $this->copied);
	}

	public function testNoOpWhenSourceMissing(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false); // source not found
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		$records = $this->createMock(RecordMapper::class);
		$records->method('findOwnerById')->willReturn('alice');
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$relations = $this->createMock(RelationResolver::class);
		$relations->method('enrich')->willReturnArgument(2);
		$action = new ApplyTemplateAction($root, $records, $users, new ValueInterpolator(), $relations, $this->settings(), $this->createMock(LoggerInterface::class));
		$action->run($this->ctx());
		$this->assertSame([], $this->copied);
		$this->assertSame('apply_template', $action->getType());
	}
}
