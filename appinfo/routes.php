<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * OCS API routes. The SPA page route itself is declared via the
 * #[FrontpageRoute] attribute on PageController.
 */
return [
	'ocs' => [
		['name' => 'register#index', 'url' => '/api/v1/registers', 'verb' => 'GET'],
		['name' => 'register#create', 'url' => '/api/v1/registers', 'verb' => 'POST'],
		['name' => 'register#show', 'url' => '/api/v1/registers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'register#update', 'url' => '/api/v1/registers/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'register#destroy', 'url' => '/api/v1/registers/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Fields (a register's schema)
		['name' => 'field#index', 'url' => '/api/v1/registers/{registerId}/fields', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'field#create', 'url' => '/api/v1/registers/{registerId}/fields', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'field#reorder', 'url' => '/api/v1/registers/{registerId}/fields/reorder', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'field#update', 'url' => '/api/v1/fields/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'field#destroy', 'url' => '/api/v1/fields/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
	],
];
