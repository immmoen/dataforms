<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Controller;

use OCA\Dataforms\Exception\ForbiddenException;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Maps the application's domain exceptions to the conventional OCS responses,
 * so the controllers express the happy path once instead of repeating the same
 * three (or four) catch blocks on every action:
 *
 *   - {@see ValidationException} → 400 Bad Request (or a caller-supplied status,
 *     e.g. 422 for an integrity rule), carrying its per-field `errors` map only
 *     when it is non-empty (matching the prior per-controller behaviour).
 *   - {@see NotFoundException}   → 404 Not Found.
 *   - {@see ForbiddenException}  → 403 Forbidden.
 *
 * The body is always `['message' => <exception message>]` (plus `errors` for a
 * non-empty validation map), identical to the responses these controllers
 * produced before the catch blocks were unified.
 */
trait HandlesApiExceptions {
	/**
	 * Run $work, returning its result as a {@see DataResponse} with $successStatus,
	 * and mapping the domain exceptions to their OCS status codes.
	 *
	 * @param callable():mixed $work the happy-path operation
	 * @param int $successStatus status for the successful response (default 200)
	 * @param int $validationStatus status for a {@see ValidationException}
	 *                              (default 400; pass 422 for integrity rules)
	 */
	protected function handle(callable $work, int $successStatus = Http::STATUS_OK, int $validationStatus = Http::STATUS_BAD_REQUEST): DataResponse {
		try {
			/** @psalm-suppress ArgumentTypeCoercion the caller passes an Http::STATUS_* constant */
			return new DataResponse($work(), $successStatus);
		} catch (ValidationException $e) {
			$body = ['message' => $e->getMessage()];
			if ($e->getErrors() !== []) {
				$body['errors'] = $e->getErrors();
			}
			/** @psalm-suppress ArgumentTypeCoercion the caller passes an Http::STATUS_* constant */
			return new DataResponse($body, $validationStatus);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}
}
