<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Search;

use OCA\Dataforms\Service\FormService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified-search provider for data-entry forms. It powers the Smart Picker:
 * a searchable reference provider (FormReferenceProvider) points at this
 * provider's id, so typing in the "/" picker lists the user's forms, and
 * picking one inserts a link that opens the form.
 */
class FormSearchProvider implements IProvider {

	public function __construct(
		private FormService $formService,
		private IURLGenerator $url,
		private IL10N $l10n,
	) {
	}

	public function getId(): string {
		return 'dataforms_forms';
	}

	public function getName(): string {
		return $this->l10n->t('Dataforms forms');
	}

	public function getOrder(string $route, array $routeParameters): int {
		return 60;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$icon = $this->url->getAbsoluteURL($this->url->imagePath('dataforms', 'app-color.svg'));
		$entries = [];
		foreach ($this->formService->searchForPicker($user->getUID(), $query->getTerm(), $query->getLimit()) as $f) {
			$entries[] = new SearchResultEntry(
				$icon,
				$f['formTitle'],
				$f['registerTitle'],
				$this->url->getAbsoluteURL(
					$this->url->linkToRoute('dataforms.page.index')
					. '?register=' . $f['registerId'] . '&form=' . $f['formId']
				),
				'',
				false,
			);
		}
		return SearchResult::complete($this->getName(), $entries);
	}
}
