<?php

declare(strict_types=1);

namespace OneClickTranslation\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( static fn( string $name, mixed $default = null ): mixed=>$default );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, mixed $value ): mixed=>$value );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown(); }
}
