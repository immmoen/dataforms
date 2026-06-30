<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// Minimal stubs for `OC\…` symbols that the public `nextcloud/ocp` package
// references but does not ship (it stubs only the OCP/NCU public API). Without
// them, reflecting/mocking certain OCP interfaces fatals at unit-test time:
// e.g. OCP\Files\IRootFolder extends OC\Hooks\Emitter. Production uses the real
// server classes; these exist purely so the mock generator can resolve the
// inheritance chain. Dev-only — excluded from the App-Store build.

namespace OC\Hooks {
	if (!interface_exists(Emitter::class)) {
		interface Emitter {
		}
	}
}

namespace OC\User {
	if (!class_exists(NoUserException::class)) {
		class NoUserException extends \Exception {
		}
	}
}

// nextcloud/ocp's DownloadResponse stub builds a Content-Disposition header via
// Symfony's UnicodeString + HeaderUtils (shipped by the real server, not the
// stub package). Minimal stand-ins so DataDownloadResponse is constructible in
// unit tests; the header value itself is not asserted.

namespace Symfony\Component\String {
	if (!class_exists(UnicodeString::class)) {
		class UnicodeString {
			public function __construct(
				private string $value = '',
			) {
			}
			public function ascii(): self {
				return $this;
			}
			public function toString(): string {
				return $this->value;
			}
		}
	}
}

namespace Symfony\Component\HttpFoundation {
	if (!class_exists(HeaderUtils::class)) {
		class HeaderUtils {
			public const DISPOSITION_ATTACHMENT = 'attachment';
			public static function makeDisposition(string $disposition, string $filename, string $filenameFallback = ''): string {
				return $disposition . '; filename="' . $filename . '"';
			}
		}
	}
}
