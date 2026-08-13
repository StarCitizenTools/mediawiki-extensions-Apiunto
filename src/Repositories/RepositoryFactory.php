<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Apiunto\Repositories;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Builds repository instances with their service dependencies injected.
 */
class RepositoryFactory {

	public function __construct(
		private readonly HttpRequestFactory $requestFactory,
		private readonly Config $config,
		private readonly WANObjectCache $cache
	) {
	}

	/**
	 * Build a RawRepository for one API source.
	 *
	 * @param string $sourceName Configured source name (a key of $wgApiuntoSources).
	 * @param array $sourceConfig Source config (e.g. baseUrl, token, timeout, cacheDuration,
	 *   followRedirects).
	 * @param array $options Request options, keyed by ApiuntoLuaLibrary::IDENTIFIER and
	 *   ApiuntoLuaLibrary::QUERY_PARAMS, appended to the request URL.
	 */
	public function newRawRepository(
		string $sourceName,
		array $sourceConfig,
		array $options = []
	): RawRepository {
		return new RawRepository(
			$this->requestFactory,
			$this->config,
			$this->cache,
			$sourceName,
			$sourceConfig,
			$options
		);
	}
}
