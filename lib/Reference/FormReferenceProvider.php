<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Reference;

use OCA\Dataforms\Service\FormService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Reference provider for Dataforms form links. Makes "Dataforms" appear in the
 * Smart Picker (via the linked search provider) and renders an inserted form
 * link as a rich card (title = form, description = register). Internal only:
 * resolving a reference re-checks the current user's read access.
 */
class FormReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {

	public function __construct(
		private FormService $formService,
		private IURLGenerator $url,
		private IL10N $l10n,
		private IUserSession $userSession,
	) {
	}

	public function getId(): string {
		return 'dataforms-form';
	}

	public function getTitle(): string {
		return $this->l10n->t('Dataforms form');
	}

	public function getOrder(): int {
		return 10;
	}

	public function getIconUrl(): string {
		// Coloured variant — the picker and card sit on a light background where
		// the white header icon would be invisible.
		return $this->url->getAbsoluteURL($this->url->imagePath('dataforms', 'app-color.svg'));
	}

	/** @return string[] */
	public function getSupportedSearchProviderIds(): array {
		return ['dataforms_forms'];
	}

	public function matchReference(string $referenceText): bool {
		return $this->extract($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$ids = $this->extract($referenceText);
		if ($ids === null) {
			return null;
		}
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		$info = $this->formService->pickerInfo($userId, $ids['form']);
		if ($info === null) {
			return null;
		}
		$reference = new Reference($referenceText);
		$reference->setTitle($info['formTitle']);
		$reference->setDescription($this->l10n->t('Form in %1$s', [$info['registerTitle']]));
		$reference->setImageUrl($this->getIconUrl());
		$reference->setUrl($referenceText);
		$reference->setRichObject('dataforms_form', [
			'id' => $info['formId'],
			'name' => $info['formTitle'],
			'register' => $info['registerTitle'],
			'registerId' => $info['registerId'],
			'url' => $referenceText,
		]);
		return $reference;
	}

	public function getCachePrefix(string $referenceId): string {
		return $referenceId;
	}

	public function getCacheKey(string $referenceId): ?string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * Parse a Dataforms form link, returning ['register'=>int,'form'=>int] or
	 * null when the text isn't one of our form URLs.
	 *
	 * @return array{register:int,form:int}|null
	 */
	private function extract(string $referenceText): ?array {
		if (!str_contains($referenceText, '/apps/dataforms/')) {
			return null;
		}
		$query = parse_url($referenceText, PHP_URL_QUERY);
		if (!is_string($query) || $query === '') {
			return null;
		}
		parse_str($query, $params);
		$form = (int)($params['form'] ?? 0);
		if ($form <= 0) {
			return null;
		}
		return ['register' => (int)($params['register'] ?? 0), 'form' => $form];
	}
}
