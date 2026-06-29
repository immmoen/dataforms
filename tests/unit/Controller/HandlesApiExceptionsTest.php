<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\HandlesApiExceptions;
use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\TestCase;

/**
 * The shared exception-to-response mapper. Pins the status/body each domain
 * exception maps to — the contract every controller now relies on.
 */
class HandlesApiExceptionsTest extends TestCase {
	/** A minimal host exposing the protected trait method. */
	private function host(): object {
		return new class {
			use HandlesApiExceptions;

			public function run(callable $work, int $ok = Http::STATUS_OK, int $invalid = Http::STATUS_BAD_REQUEST): DataResponse {
				return $this->handle($work, $ok, $invalid);
			}
		};
	}

	public function testSuccessUsesTheDefaultStatusAndPassesTheResult(): void {
		$res = $this->host()->run(fn () => ['id' => 1]);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame(['id' => 1], $res->getData());
	}

	public function testSuccessHonoursACustomStatus(): void {
		$res = $this->host()->run(fn () => ['id' => 1], Http::STATUS_CREATED);
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
	}

	public function testValidationMapsToBadRequestWithoutEmptyErrors(): void {
		$res = $this->host()->run(function (): void {
			throw new ValidationException('bad input');
		});
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'bad input'], $res->getData());
	}

	public function testValidationIncludesANonEmptyErrorMap(): void {
		$res = $this->host()->run(function (): void {
			throw new ValidationException('Validation failed', ['title' => 'required']);
		});
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame(['message' => 'Validation failed', 'errors' => ['title' => 'required']], $res->getData());
	}

	public function testValidationHonoursACustomStatusForIntegrityRules(): void {
		$res = $this->host()->run(function (): void {
			throw new ValidationException('blocked');
		}, Http::STATUS_OK, Http::STATUS_UNPROCESSABLE_ENTITY);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		$this->assertSame(['message' => 'blocked'], $res->getData());
	}

	public function testNotFoundMapsToNotFound(): void {
		$res = $this->host()->run(function (): void {
			throw new NotFoundException('nope');
		});
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame(['message' => 'nope'], $res->getData());
	}

	public function testForbiddenMapsToForbidden(): void {
		$res = $this->host()->run(function (): void {
			throw new ForbiddenException('denied');
		});
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
		$this->assertSame(['message' => 'denied'], $res->getData());
	}
}
