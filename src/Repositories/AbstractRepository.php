<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Apiunto\Repositories;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Apiunto\ApiuntoLuaLibrary;
use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\ObjectCache\WANObjectCache;

abstract class AbstractRepository {
	public const PROP_KEY = 'apiuntocache';
	private const DEFAULT_CACHE_DURATION = 86400;

	private ?string $cacheKey = null;

	public function __construct(
		protected readonly HttpRequestFactory $requestFactory,
		private readonly Config $config,
		private readonly WANObjectCache $cache,
		protected readonly string $sourceName,
		protected readonly array $sourceConfig,
		protected array $options = []
	) {
	}

	/**
	 * Perform the request, returning the response body (or an error string).
	 */
	protected function request(): string {
		$cacheMiss = false;
		$caller = __METHOD__;
		$callback = function () use ( &$cacheMiss, $caller ) {
			$cacheMiss = true;
			wfDebugLog( 'Apiunto', 'Retrieving Data from API' );

			try {
				// Opt-in per source: resolver endpoints answer with a 302 to the record.
				$req = $this->requestFactory->create( $this->getFullUrl(), [
					'timeout' => $this->sourceConfig['timeout'] ?? 5,
					'followRedirects' => $this->sourceConfig['followRedirects'] ?? false,
				], $caller );
				$req->setHeader( 'User-Agent', 'MediaWiki/ext-apiunto-' . MW_VERSION );
				if ( !empty( $this->sourceConfig['token'] ) ) {
					$req->setHeader( 'Authorization', 'Bearer ' . $this->sourceConfig['token'] );
				}
				$status = $req->execute();

				if ( !$status->isOK() ) {
					return false;
				}

				return $req->getContent();
			} catch ( Exception $e ) {
				wfLogWarning( sprintf( '[Apiunto] Error retrieving API data: %s', $e->getMessage() ) );
				wfDebugLog( 'Apiunto', sprintf( 'Error retrieving API data: %s', $e->getMessage() ) );

				$key = $this->makeCacheKey();
				$stale = $this->cache->get( $key );

				if ( $stale !== false ) {
					wfLogWarning( sprintf( '[Apiunto] Returning stale content for key %s', $key ) );
					wfDebugLog( 'Apiunto', sprintf( 'Returning stale content for key %s', $key ) );
					return $stale;
				}

				return false;
			}
		};

		if ( $this->config->get( 'ApiuntoEnableCache' ) !== true ) {
			wfDebugLog( 'Apiunto', 'Object cache is disabled' );
			return (string)$callback();
		}

		$key = $this->makeCacheKey();
		$value = $this->cache->getWithSetCallback(
			$key,
			$this->sourceConfig['cacheDuration'] ?? self::DEFAULT_CACHE_DURATION,
			$callback
		);

		wfDebugLog( 'Apiunto', sprintf(
			$cacheMiss ? 'Object cache MISS: %s' : 'Object cache HIT: %s',
			$key
		) );

		if ( $value === false ) {
			return 'Could not retrieve API Data';
		}

		return (string)$value;
	}

	/**
	 * Creates a key for caching.
	 */
	public function makeCacheKey(): string {
		if ( $this->cacheKey !== null ) {
			return $this->cacheKey;
		}

		// Hash the request URL so the key stays short and bounded. The readable URL is
		// recorded separately in the page property for display on action=info.
		$this->cacheKey = $this->cache->makeKey(
			'ext',
			self::PROP_KEY,
			sha1( $this->getRequestUrl() )
		);

		return $this->cacheKey;
	}

	/**
	 * The full request URL (base URL + identifier + query string).
	 */
	public function getRequestUrl(): string {
		return $this->getFullUrl();
	}

	private function getFullUrl(): string {
		$queryString = $this->buildQueryString();

		$baseUrl = rtrim( $this->sourceConfig['baseUrl'], '/' );
		$identifier = ltrim( $this->options[ApiuntoLuaLibrary::IDENTIFIER], '/' );
		$fullUrl = $baseUrl . '/' . $identifier;
		if ( $queryString ) {
			$fullUrl .= '?' . $queryString;
		}

		return $fullUrl;
	}

	private function buildQueryString(): string {
		$queryParams = $this->options[ ApiuntoLuaLibrary::QUERY_PARAMS ];
		ksort( $queryParams );
		return http_build_query( $queryParams );
	}

}
