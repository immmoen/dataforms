<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\ExportController;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Service\RecordService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ExportController: streams a register's records as a CSV download. Covers the
 * UTF-8 BOM (so Excel detects the encoding), the value rendering (arrays joined,
 * booleans as yes/no), the spreadsheet formula-injection guard, and the
 * not-found mapping.
 */
class ExportControllerTest extends TestCase {
	private RecordService&MockObject $service;
	private IUserSession&MockObject $userSession;
	private ExportController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(RecordService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->controller = new ExportController($this->createMock(IRequest::class), $this->service, $this->userSession);
	}

	public function testMissingRegisterMapsToNotFound(): void {
		$this->service->method('list')->willThrowException(new NotFoundException('no register'));
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->csv(5)->getStatus());
	}

	public function testExportsCsvWithBomHeaderAndValues(): void {
		$this->service->method('list')->willReturn([
			'fields' => [
				['machineName' => 'title', 'label' => 'Title'],
				['machineName' => 'tags', 'label' => 'Tags'],
				['machineName' => 'done', 'label' => 'Done'],
			],
			'records' => [
				['id' => 1, 'values' => ['title' => 'Hello', 'tags' => ['a', 'b'], 'done' => true]],
				['id' => 2, 'values' => ['title' => 'World', 'done' => false]],
			],
			'total' => 2,
		]);

		$res = $this->controller->csv(5);
		$this->assertInstanceOf(DataDownloadResponse::class, $res);
		$csv = $res->render();

		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv); // UTF-8 BOM
		$this->assertStringContainsString('id,Title,Tags,Done', $csv);
		$this->assertStringContainsString('1,Hello,"a, b",yes', $csv); // array joined, bool → yes
		$this->assertStringContainsString('2,World,,no', $csv);         // missing value blank, bool → no
	}

	public function testNeutralisesSpreadsheetFormulaInjection(): void {
		$this->service->method('list')->willReturn([
			'fields' => [
				['machineName' => 'a', 'label' => 'A'],
				['machineName' => 'b', 'label' => 'B'],
				['machineName' => 'c', 'label' => 'C'],
			],
			'records' => [[
				'id' => 1,
				'values' => ['a' => '=HYPERLINK("evil")', 'b' => '-5', 'c' => "\tcontrol"],
			]],
			'total' => 1,
		]);

		$csv = $this->controller->csv(5)->render();
		// A formula-leading cell is quoted to a literal; a genuine negative number is
		// left as-is; a control-char-leading cell is quoted too.
		$this->assertStringContainsString("'=HYPERLINK", $csv);
		$this->assertStringContainsString("'\tcontrol", $csv);
		$this->assertMatchesRegularExpression('/,-5,/', $csv); // numeric -5 untouched
	}

	public function testAnonymousUserStillGetsTheirEmptyOrAccessibleExport(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$controller = new ExportController($this->createMock(IRequest::class), $this->service, $this->userSession);
		$this->service->expects($this->once())->method('list')->with('', 5, 500, 0, 'updated', 'DESC', '')
			->willReturn(['fields' => [], 'records' => [], 'total' => 0]);
		$this->assertInstanceOf(DataDownloadResponse::class, $controller->csv(5));
	}
}
