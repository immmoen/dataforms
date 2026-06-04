<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * OCS API routes. The SPA page route itself is declared via the
 * #[FrontpageRoute] attribute on PageController.
 */
return [
	'routes' => [
		// CSV export (normal route so it can return a file download).
		['name' => 'export#csv', 'url' => '/registers/{registerId}/export/csv', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		// File upload from the user's computer (normal route for multipart).
		['name' => 'upload#upload', 'url' => '/upload', 'verb' => 'POST'],
	],
	'ocs' => [
		['name' => 'register#index', 'url' => '/api/v1/registers', 'verb' => 'GET'],
		['name' => 'register#create', 'url' => '/api/v1/registers', 'verb' => 'POST'],
		['name' => 'register#show', 'url' => '/api/v1/registers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'register#update', 'url' => '/api/v1/registers/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'register#favorite', 'url' => '/api/v1/registers/{id}/favorite', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'register#destroy', 'url' => '/api/v1/registers/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Fields (a register's schema)
		['name' => 'field#index', 'url' => '/api/v1/registers/{registerId}/fields', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'field#create', 'url' => '/api/v1/registers/{registerId}/fields', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'field#reorder', 'url' => '/api/v1/registers/{registerId}/fields/reorder', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'field#update', 'url' => '/api/v1/fields/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'field#destroy', 'url' => '/api/v1/fields/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Records (data)
		['name' => 'record#index', 'url' => '/api/v1/registers/{registerId}/records', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'record#create', 'url' => '/api/v1/registers/{registerId}/records', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'record#options', 'url' => '/api/v1/registers/{registerId}/options', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'record#import', 'url' => '/api/v1/registers/{registerId}/import', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'record#show', 'url' => '/api/v1/records/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'record#update', 'url' => '/api/v1/records/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'record#destroy', 'url' => '/api/v1/records/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Rules (conditional logic)
		['name' => 'rule#index', 'url' => '/api/v1/registers/{registerId}/rules', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'rule#create', 'url' => '/api/v1/registers/{registerId}/rules', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'rule#update', 'url' => '/api/v1/rules/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'rule#destroy', 'url' => '/api/v1/rules/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// File resolution (path -> id) for file-attachment fields
		['name' => 'file#resolve', 'url' => '/api/v1/files/resolve', 'verb' => 'GET'],

		// Data-entry forms
		['name' => 'form#index', 'url' => '/api/v1/registers/{registerId}/forms', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'form#create', 'url' => '/api/v1/registers/{registerId}/forms', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'form#update', 'url' => '/api/v1/forms/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'form#destroy', 'url' => '/api/v1/forms/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Saved views
		['name' => 'view#index', 'url' => '/api/v1/registers/{registerId}/views', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'view#create', 'url' => '/api/v1/registers/{registerId}/views', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'view#update', 'url' => '/api/v1/views/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'view#destroy', 'url' => '/api/v1/views/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

		// Shares (register ACL)
		['name' => 'share#index', 'url' => '/api/v1/registers/{registerId}/shares', 'verb' => 'GET', 'requirements' => ['registerId' => '\d+']],
		['name' => 'share#create', 'url' => '/api/v1/registers/{registerId}/shares', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
		['name' => 'share#update', 'url' => '/api/v1/shares/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'share#destroy', 'url' => '/api/v1/shares/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
	],
];
