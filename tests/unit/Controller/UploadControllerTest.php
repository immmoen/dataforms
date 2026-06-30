<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\UploadController;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * UploadController: stores a computer-uploaded file under the user's "Dataforms"
 * Files folder and returns its id + name (the attachment is referenced by id,
 * never blobbed into the app DB). Covers the auth gate, the missing-file guard,
 * the folder create/reuse branches, and the permission-failure mapping.
 */
class UploadControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private UploadController $controller;
	private string $tmp = '';

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new UploadController($this->request, $this->rootFolder, $this->userSession);
	}

	protected function tearDown(): void {
		if ($this->tmp !== '' && is_file($this->tmp)) {
			unlink($this->tmp);
		}
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/** A real temp file so file_get_contents(tmp_name) succeeds on the happy path. */
	private function uploadedFile(string $name, int $error = UPLOAD_ERR_OK): array {
		$this->tmp = (string)tempnam(sys_get_temp_dir(), 'dftest');
		file_put_contents($this->tmp, 'hello');
		return ['name' => $name, 'tmp_name' => $this->tmp, 'error' => $error];
	}

	public function testRejectsAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$res = $this->controller->upload();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
	}

	public function testRejectsMissingOrErroredFile(): void {
		$this->loginAs('alice');
		$this->request->method('getUploadedFile')->willReturn([]);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->upload()->getStatus());

		$this->request = $this->createMock(IRequest::class);
		$this->controller = new UploadController($this->request, $this->rootFolder, $this->userSession);
		$this->request->method('getUploadedFile')->willReturn(['name' => 'x', 'tmp_name' => '/none', 'error' => UPLOAD_ERR_NO_FILE]);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->upload()->getStatus());
	}

	public function testStoresInExistingDataformsFolderAndReturnsIdAndName(): void {
		$this->loginAs('alice');
		$this->request->method('getUploadedFile')->willReturn($this->uploadedFile('report.pdf'));

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(501);
		$node->method('getName')->willReturn('report.pdf');

		$folder = $this->createMock(Folder::class);
		$folder->method('getNonExistingName')->with('report.pdf')->willReturn('report.pdf');
		$folder->expects($this->once())->method('newFile')->with('report.pdf', 'hello')->willReturn($node);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->with('Dataforms')->willReturn(true);
		$userFolder->method('get')->with('Dataforms')->willReturn($folder);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$res = $this->controller->upload();
		$this->assertSame(['id' => 501, 'name' => 'report.pdf'], $res->getData());
	}

	public function testCreatesDataformsFolderWhenMissing(): void {
		$this->loginAs('alice');
		$this->request->method('getUploadedFile')->willReturn($this->uploadedFile('a.txt'));

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(9);
		$node->method('getName')->willReturn('a.txt');
		$folder = $this->createMock(Folder::class);
		$folder->method('getNonExistingName')->willReturnArgument(0);
		$folder->method('newFile')->willReturn($node);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->expects($this->once())->method('newFolder')->with('Dataforms')->willReturn($folder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->assertSame(9, $this->controller->upload()->getData()['id']);
	}

	public function testReturns500WhenTargetIsNotAFolder(): void {
		$this->loginAs('alice');
		$this->request->method('getUploadedFile')->willReturn($this->uploadedFile('a.txt'));
		$notFolder = $this->createMock(Node::class); // a plain file occupies the name
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($notFolder);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller->upload()->getStatus());
	}

	public function testMapsPermissionFailureToForbidden(): void {
		$this->loginAs('alice');
		$this->request->method('getUploadedFile')->willReturn($this->uploadedFile('a.txt'));
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willThrowException(new NotPermittedException('denied'));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->upload()->getStatus());
	}
}
