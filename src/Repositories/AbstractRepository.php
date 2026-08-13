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
	private const MAX_REDIRECTS = 3;

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
		$callback = function ( $oldValue, &$ttl ) use ( &$cacheMiss, $caller ) {
			$cacheMiss = true;
			wfDebugLog( 'Apiunto', 'Retrieving Data from API' );

			try {
				$content = $this->fetch( $caller );
			} catch ( Exception $e ) {
				wfLogWarning( sprintf( '[Apiunto] Error retrieving API data: %s', $e->getMessage() ) );
				wfDebugLog( 'Apiunto', sprintf( 'Error retrieving API data: %s', $e->getMessage() ) );
				$content = false;
			}

			if ( $content !== false ) {
				return $content;
			}

			return $this->serveStale( $oldValue, $ttl );
		};

		if ( $this->config->get( 'ApiuntoEnableCache' ) !== true ) {
			wfDebugLog( 'Apiunto', 'Object cache is disabled' );
			$discardedTtl = 0;
			return (string)$callback( false, $discardedTtl );
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
	 * Last resort when a fetch fails: hand back the response cached from a previous
	 * run, if WANObjectCache still has one.
	 *
	 * This runs for *any* failure, not just a thrown one. MWHttpRequest reports a
	 * timeout, connection refusal, DNS failure, 429 or 5xx as a non-OK Status rather
	 * than an exception (GuzzleHttpRequest::execute() catches every GuzzleException
	 * and calls Status::fatal), so guarding the fallback on catch alone left it
	 * unreachable for the very cases it exists to cover.
	 *
	 * @param string|false $oldValue Value WANObjectCache is regenerating over.
	 * @param int|float &$ttl Set to TTL_UNCACHEABLE when stale content is served.
	 * @return string|false
	 */
	private function serveStale( $oldValue, &$ttl ) {
		if ( $oldValue === false ) {
			return false;
		}

		// Do not write the stale value back. Re-storing it under a fresh TTL would
		// stop the upstream being retried for another full cacheDuration, turning a
		// brief outage into a long stale window. Leaving the entry untouched means
		// the next request tries again.
		$ttl = WANObjectCache::TTL_UNCACHEABLE;

		wfLogWarning( sprintf( '[Apiunto] Returning stale content for key %s', $this->makeCacheKey() ) );
		wfDebugLog( 'Apiunto', sprintf( 'Returning stale content for key %s', $this->makeCacheKey() ) );

		return $oldValue;
	}

	/**
	 * Issues the HTTP request, following redirects itself when the source opts in,
	 * and returns the final response body (or false on failure).
	 *
	 * MediaWiki's own `followRedirects` option cannot be used for this.
	 * GuzzleHttpRequest streams every hop into a single MWCallbackStream sink, and
	 * Guzzle's redirect middleware reuses that same sink for the follow-up request,
	 * so getContent() returns the intermediate redirect page concatenated in front
	 * of the real payload. Issuing each hop as its own request keeps the bodies
	 * apart.
	 *
	 * @return string|false
	 */
	private function fetch( string $caller ) {
		$url = $this->getFullUrl();
		$maxHops = ( $this->sourceConfig['followRedirects'] ?? false ) ? self::MAX_REDIRECTS : 0;

		for ( $hop = 0; ; $hop++ ) {
			$req = $this->requestFactory->create( $url, [
				'timeout' => $this->sourceConfig['timeout'] ?? 5,
			], $caller );
			$req->setHeader( 'User-Agent', 'MediaWiki/ext-apiunto-' . MW_VERSION );
			// The token is scoped to the configured host: a redirect that leaves it
			// must not carry the source's credentials along.
			if ( !empty( $this->sourceConfig['token'] ) && $this->isConfiguredHost( $url ) ) {
				$req->setHeader( 'Authorization', 'Bearer ' . $this->sourceConfig['token'] );
			}

			$status = $req->execute();
			$code = $req->getStatus();

			// A 3xx passes isOK() (only >= 400 is fatal), so it must be handled before
			// the success branch or the redirect page would be returned as the payload.
			if ( $code >= 300 && $code < 400 ) {
				$next = $req->getFinalUrl();
				if ( $hop >= $maxHops || $next === '' || $next === $url ) {
					// A response condition, not an exception: request() turns the false
					// into the caller-visible error string. Debug-level so a source that
					// legitimately meets redirects doesn't flood the warning channel.
					wfDebugLog( 'Apiunto', sprintf( 'Unfollowed redirect (%d) for %s', $code, $url ) );
					return false;
				}
				$url = $next;
				continue;
			}

			if ( !$status->isOK() ) {
				return false;
			}

			return $req->getContent();
		}
	}

	/**
	 * Whether a URL is on the same host as the source's configured baseUrl.
	 */
	private function isConfiguredHost( string $url ): bool {
		return parse_url( $url, PHP_URL_HOST )
			=== parse_url( $this->sourceConfig['baseUrl'] ?? '', PHP_URL_HOST );
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
