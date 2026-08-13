<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Apiunto\Tests\Unit\Repositories;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Apiunto\ApiuntoLuaLibrary;
use MediaWiki\Extension\Apiunto\Repositories\AbstractRepository;
use MediaWiki\Extension\Apiunto\Repositories\RawRepository;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @group Apiunto
 * @group Repositories
 * @covers \MediaWiki\Extension\Apiunto\Repositories\AbstractRepository
 */
class AbstractRepositoryTest extends MediaWikiUnitTestCase {

	private function newCache(): WANObjectCache {
		$cache = $this->createMock( WANObjectCache::class );
		$cache->method( 'makeKey' )->willReturnCallback(
			static fn ( ...$args ) => implode( ':', $args )
		);
		return $cache;
	}

	/**
	 * A real WANObjectCache backed by an in-memory store, so the final
	 * getWithSetCallback() runs the production callback for real.
	 */
	private function newRealCache(): WANObjectCache {
		return new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
	}

	private function newRequestFactory( bool $ok, string $content ): HttpRequestFactory {
		$status = $this->createMock( \StatusValue::class );
		$status->method( 'isOK' )->willReturn( $ok );

		$req = $this->createMock( \MWHttpRequest::class );
		$req->method( 'execute' )->willReturn( $status );
		$req->method( 'getContent' )->willReturn( $content );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturn( $req );
		return $factory;
	}

	/**
	 * A factory that answers successive create() calls from a queue of canned
	 * responses, recording the URL and request headers of each hop.
	 *
	 * @param array[] $responses Each: [ 'code' => int, 'location' => string, 'content' => string ]
	 * @param string[] &$urls Receives the URL of each hop, in order.
	 * @param array[] &$headers Receives the headers set on each hop, in order.
	 */
	private function newHopRequestFactory( array $responses, array &$urls, array &$headers ): HttpRequestFactory {
		$reqs = [];
		foreach ( $responses as $i => $response ) {
			$code = $response['code'];
			$status = $this->createMock( \StatusValue::class );
			$status->method( 'isOK' )->willReturn( $code < 400 );

			$req = $this->createMock( \MWHttpRequest::class );
			$req->method( 'execute' )->willReturn( $status );
			$req->method( 'getStatus' )->willReturn( $code );
			$req->method( 'getFinalUrl' )->willReturn( $response['location'] ?? '' );
			$req->method( 'getContent' )->willReturn( $response['content'] ?? '' );
			$req->method( 'setHeader' )->willReturnCallback(
				static function ( $name, $value ) use ( &$headers, $i ) {
					$headers[$i][$name] = $value;
				}
			);
			$reqs[] = $req;
		}

		$hop = 0;
		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			static function ( string $url, array $options = [], string $caller = '' ) use ( &$urls, &$hop, $reqs ) {
				$urls[] = $url;
				return $reqs[$hop++];
			}
		);
		return $factory;
	}

	private function newRepo(
		array $sourceConfig,
		array $options,
		?HttpRequestFactory $factory = null,
		?WANObjectCache $cache = null,
		bool $cacheEnabled = false
	): RawRepository {
		return new RawRepository(
			$factory ?? $this->createMock( HttpRequestFactory::class ),
			new HashConfig( [ 'ApiuntoEnableCache' => $cacheEnabled ] ),
			$cache ?? $this->newCache(),
			'ships',
			$sourceConfig,
			$options
		);
	}

	public function testPropKeyConstant(): void {
		$this->assertSame( 'apiuntocache', AbstractRepository::PROP_KEY );
	}

	public function testRequestUrlTrimsSlashesAndSortsQuery(): void {
		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example/' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => '/Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [ 'b' => '2', 'a' => '1' ],
			]
		);

		$this->assertSame( 'https://api.example/Aurora?a=1&b=2', $repo->getRequestUrl() );
	}

	public function testMakeCacheKeyIsStableAndHashesUrl(): void {
		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			]
		);

		$expected = 'ext:apiuntocache:' . sha1( 'https://api.example/Aurora' );
		$this->assertSame( $expected, $repo->makeCacheKey() );
		$this->assertSame( $repo->makeCacheKey(), $repo->makeCacheKey(), 'memoized' );
	}

	/**
	 * Caching is left enabled with a real backing store so a failed fetch surfaces
	 * as the production error string rather than the cache-disabled empty string.
	 */
	private function newRedirectRepo(
		array $sourceConfig,
		array $responses,
		array &$urls,
		array &$headers
	): RawRepository {
		return $this->newRepo(
			$sourceConfig,
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'search/Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$this->newHopRequestFactory( $responses, $urls, $headers ),
			$this->newRealCache(),
			true
		);
	}

	public function testDoesNotFollowRedirectByDefault(): void {
		$urls = [];
		$headers = [];
		$repo = $this->newRedirectRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[ [ 'code' => 302, 'location' => 'https://api.example/ships/aurora', 'content' => '<html>go</html>' ] ],
			$urls,
			$headers
		);

		$this->assertSame( 'Could not retrieve API Data', $repo->getRaw() );
		$this->assertCount( 1, $urls, 'the redirect must not be followed' );
	}

	/**
	 * The redirect page's body must not survive into the result. MediaWiki's own
	 * followRedirects option concatenates it in front of the payload, which is why
	 * the hops are issued separately.
	 */
	public function testFollowsRedirectAndReturnsOnlyFinalBody(): void {
		$urls = [];
		$headers = [];
		$repo = $this->newRedirectRepo(
			[ 'baseUrl' => 'https://api.example', 'followRedirects' => true ],
			[
				[ 'code' => 302, 'location' => 'https://api.example/ships/aurora', 'content' => '<html>go</html>' ],
				[ 'code' => 200, 'content' => '{"data":{"name":"Aurora"}}' ],
			],
			$urls,
			$headers
		);

		$this->assertSame( '{"data":{"name":"Aurora"}}', $repo->getRaw() );
		$this->assertSame(
			[ 'https://api.example/search/Aurora', 'https://api.example/ships/aurora' ],
			$urls
		);
	}

	public function testGivesUpAfterMaxRedirects(): void {
		$urls = [];
		$headers = [];
		$hop = static fn ( int $n ) => [
			'code' => 302,
			'location' => 'https://api.example/hop' . $n,
			'content' => '',
		];
		$repo = $this->newRedirectRepo(
			[ 'baseUrl' => 'https://api.example', 'followRedirects' => true ],
			[ $hop( 1 ), $hop( 2 ), $hop( 3 ), $hop( 4 ), $hop( 5 ) ],
			$urls,
			$headers
		);

		$this->assertSame( 'Could not retrieve API Data', $repo->getRaw() );
		$this->assertCount( 4, $urls, 'the original request plus MAX_REDIRECTS hops' );
	}

	/**
	 * A redirect that leaves the configured host must not carry the source's token.
	 */
	public function testDropsTokenOnCrossHostRedirect(): void {
		$urls = [];
		$headers = [];
		$repo = $this->newRedirectRepo(
			[ 'baseUrl' => 'https://api.example', 'followRedirects' => true, 'token' => 'secret' ],
			[
				[ 'code' => 302, 'location' => 'https://cdn.elsewhere/ships/aurora', 'content' => '' ],
				[ 'code' => 200, 'content' => '{}' ],
			],
			$urls,
			$headers
		);

		$repo->getRaw();

		$this->assertSame( 'Bearer secret', $headers[0]['Authorization'] ?? null );
		$this->assertArrayNotHasKey( 'Authorization', $headers[1] );
	}

	public function testKeepsTokenOnSameHostRedirect(): void {
		$urls = [];
		$headers = [];
		$repo = $this->newRedirectRepo(
			[ 'baseUrl' => 'https://api.example', 'followRedirects' => true, 'token' => 'secret' ],
			[
				[ 'code' => 302, 'location' => 'https://api.example/ships/aurora', 'content' => '' ],
				[ 'code' => 200, 'content' => '{}' ],
			],
			$urls,
			$headers
		);

		$repo->getRaw();

		$this->assertSame( 'Bearer secret', $headers[1]['Authorization'] ?? null );
	}

	/**
	 * Seeds a logically-stale but physically-retained value, so getWithSetCallback
	 * runs the regeneration callback while the old value is still readable.
	 */
	private function seedStale( WANObjectCache $cache, AbstractRepository $repo, float &$mockTime ): void {
		$cache->set( $repo->makeCacheKey(), 'stale-but-served', 10, [ 'staleTTL' => 3600 ] );
		$mockTime += 20;
	}

	/**
	 * The regression this guards. MWHttpRequest reports a 429, 5xx or timeout as a
	 * non-OK Status rather than throwing, so a fallback guarded on catch alone never
	 * ran for the very failures it exists to cover.
	 */
	public function testServesStaleWhenHttpFailsWithoutThrowing(): void {
		$mockTime = microtime( true );
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$cache->setMockTime( $mockTime );

		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$this->newRequestFactory( false, '' ),
			$cache,
			true
		);

		$this->seedStale( $cache, $repo, $mockTime );

		$raw = null;
		$this->expectPHPError(
			E_USER_WARNING,
			static function () use ( $repo, &$raw ) {
				$raw = $repo->getRaw();
			}
		);

		$this->assertSame( 'stale-but-served', $raw );
	}

	/**
	 * Stale content must not be written back. Re-storing it under a fresh TTL would
	 * stop the upstream being retried for another full cacheDuration, so a brief
	 * outage would become a long stale window.
	 */
	public function testStaleContentIsNotWrittenBackToCache(): void {
		$mockTime = microtime( true );
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$cache->setMockTime( $mockTime );

		$status = $this->createMock( \StatusValue::class );
		$status->method( 'isOK' )->willReturn( false );
		$req = $this->createMock( \MWHttpRequest::class );
		$req->method( 'execute' )->willReturn( $status );

		$factory = $this->createMock( HttpRequestFactory::class );
		// Both calls must reach the network. Had the stale value been re-stored, the
		// second would be a cache hit and never call create().
		$factory->expects( $this->exactly( 2 ) )->method( 'create' )->willReturn( $req );

		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$factory,
			$cache,
			true
		);

		$this->seedStale( $cache, $repo, $mockTime );

		$results = [];
		foreach ( [ 0, 1 ] as $_ ) {
			$this->expectPHPError(
				E_USER_WARNING,
				static function () use ( $repo, &$results ) {
					$results[] = $repo->getRaw();
				}
			);
		}

		$this->assertSame( [ 'stale-but-served', 'stale-but-served' ], $results );
	}

	public function testRequestWithCacheDisabledHitsApiDirectly(): void {
		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$this->newRequestFactory( true, '{"ok":true}' ),
			null,
			false
		);

		$this->assertSame( '{"ok":true}', $repo->getRaw() );
	}

	public function testRequestUsesCacheCallbackWhenEnabled(): void {
		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$this->newRequestFactory( true, '{"cached":true}' ),
			$this->newRealCache(),
			true
		);

		$this->assertSame( '{"cached":true}', $repo->getRaw() );
	}

	public function testRequestReturnsErrorStringWhenCacheValueFalse(): void {
		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$this->newRequestFactory( false, '' ),
			$this->newRealCache(),
			true
		);

		$this->assertSame( 'Could not retrieve API Data', $repo->getRaw() );
	}

	public function testReturnsStaleCachedValueWhenHttpFails(): void {
		// Use mock time so we can drive logical staleness deterministically.
		$mockTime = microtime( true );
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$cache->setMockTime( $mockTime );

		// HTTP must throw so the regeneration callback fails and falls into the
		// catch-block stale-serve path.
		$req = $this->createMock( \MWHttpRequest::class );
		$req->method( 'execute' )->willThrowException( new \RuntimeException( 'network down' ) );
		$factory = $this->createMock( HttpRequestFactory::class );
		// create() is ONLY reached from inside the regeneration callback. Requiring
		// exactly one call proves getWithSetCallback() invoked the callback (logical
		// staleness) rather than serving a fresh value directly.
		$factory->expects( $this->once() )->method( 'create' )->willReturn( $req );

		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$factory,
			$cache,
			true
		);

		// Seed with a short logical TTL but a long staleTTL: after advancing past
		// the logical TTL the value is logically stale (so getWithSetCallback runs
		// the callback to regenerate) yet physically retained (so the callback's
		// own $this->cache->get() can still read it).
		$cache->set( $repo->makeCacheKey(), 'stale-but-served', 10, [ 'staleTTL' => 3600 ] );
		$mockTime += 20;

		// The catch-block stale-serve path emits E_USER_WARNING via wfLogWarning();
		// the suite converts warnings to exceptions, so capture it here. This also
		// asserts that the catch branch was actually entered.
		$raw = null;
		$this->expectPHPError(
			E_USER_WARNING,
			static function () use ( $repo, &$raw ) {
				$raw = $repo->getRaw();
			}
		);

		$this->assertSame( 'stale-but-served', $raw );
	}
}
