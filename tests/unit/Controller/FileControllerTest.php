<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\FileController;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * FileController: resolves a path chosen in the Nextcloud file picker to its
 * file id + name, so a file-attachment field stores the id (never a blob).
 */
class FileControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private ISession&MockObject $session;
	private FileController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->session = $this->createMock(ISession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->controller = new FileController($this->request, $this->rootFolder, $this->userSession, $this->session);
	}

	public function testResolvesPathToIdAndNameAndReleasesSession(): void {
		$this->session->expects($this->once())->method('close');
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(700);
		$node->method('getName')->willReturn('photo.png');
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('Photos/photo.png')->willReturn($node);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$res = $this->controller->resolve('Photos/photo.png');
		$this->assertSame(['id' => 700, 'name' => 'photo.png'], $res->getData());
	}

	public function testMissingPathMapsToNotFound(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willThrowException(new NotFoundException('gone'));
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$res = $this->controller->resolve('nope.txt');
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
	}
}
