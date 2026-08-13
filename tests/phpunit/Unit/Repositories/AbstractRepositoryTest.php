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
	 * Like newRequestFactory(), but records the options create() was called with.
	 *
	 * @param array|null &$captured Receives the request options.
	 */
	private function newCapturingRequestFactory( ?array &$captured ): HttpRequestFactory {
		$status = $this->createMock( \StatusValue::class );
		$status->method( 'isOK' )->willReturn( true );

		$req = $this->createMock( \MWHttpRequest::class );
		$req->method( 'execute' )->willReturn( $status );
		$req->method( 'getContent' )->willReturn( '{}' );

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			static function ( string $url, array $options = [], string $caller = '' ) use ( &$captured, $req ) {
				$captured = $options;
				return $req;
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

	public function testRequestDoesNotFollowRedirectsByDefault(): void {
		$captured = null;
		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example' ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$this->newCapturingRequestFactory( $captured )
		);

		$repo->getRaw();

		$this->assertFalse( $captured['followRedirects'] );
	}

	public function testRequestFollowsRedirectsWhenSourceOptsIn(): void {
		$captured = null;
		$repo = $this->newRepo(
			[ 'baseUrl' => 'https://api.example', 'followRedirects' => true ],
			[
				ApiuntoLuaLibrary::IDENTIFIER => 'search/Aurora',
				ApiuntoLuaLibrary::QUERY_PARAMS => [],
			],
			$this->newCapturingRequestFactory( $captured )
		);

		$repo->getRaw();

		$this->assertTrue( $captured['followRedirects'] );
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
