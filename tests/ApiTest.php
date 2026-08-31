<?php
/**
 * Unit tests for PluginHub\API validation and comparison logic.
 *
 * @package PluginHub
 */

namespace PluginHub\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PluginHub\API;
use ReflectionMethod;

final class ApiTest extends TestCase {

	private API $api;

	protected function setUp(): void {
		$this->api = new API();
	}

	/**
	 * Call a private/protected API method for testing.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed
	 */
	private function call( string $method, array $args ) {
		$reflection = new ReflectionMethod( API::class, $method );
		return $reflection->invokeArgs( $this->api, $args );
	}

	public function test_update_available_when_newer_version_published(): void {
		$repo = array(
			'available' => true,
			'version'   => '1.2.0',
		);
		$this->assertTrue( $this->api->is_update_available( $repo, '1.1.0' ) );
	}

	public function test_no_update_when_installed_version_is_current(): void {
		$repo = array(
			'available' => true,
			'version'   => '1.2.0',
		);
		$this->assertFalse( $this->api->is_update_available( $repo, '1.2.0' ) );
	}

	public function test_no_update_when_installed_version_is_newer(): void {
		$repo = array(
			'available' => true,
			'version'   => '1.2.0',
		);
		$this->assertFalse( $this->api->is_update_available( $repo, '1.3.0' ) );
	}

	public function test_no_update_when_repo_has_no_available_release(): void {
		$repo = array(
			'available' => false,
			'version'   => '1.2.0',
		);
		$this->assertFalse( $this->api->is_update_available( $repo, '1.0.0' ) );
	}

	public function test_no_update_when_plugin_is_not_installed(): void {
		$repo = array(
			'available' => true,
			'version'   => '1.2.0',
		);
		$this->assertFalse( $this->api->is_update_available( $repo, 'Not Installed' ) );
	}

	#[DataProvider( 'github_url_provider' )]
	public function test_is_github_url( string $url, bool $expected ): void {
		$this->assertSame( $expected, $this->call( 'is_github_url', array( $url ) ) );
	}

	public static function github_url_provider(): array {
		return array(
			'github.com over https'                  => array( 'https://github.com/Open-WP-Club/plugin-hub', true ),
			'api.github.com over https'               => array( 'https://api.github.com/repos/Open-WP-Club/plugin-hub', true ),
			'codeload.github.com over https'          => array( 'https://codeload.github.com/Open-WP-Club/plugin-hub/zip/refs/heads/main', true ),
			'objects.githubusercontent.com over https' => array( 'https://objects.githubusercontent.com/some/asset', true ),
			'plain http is rejected'                  => array( 'http://github.com/Open-WP-Club/plugin-hub', false ),
			'unrelated host is rejected'              => array( 'https://evil.example.com/plugin-hub.zip', false ),
			'lookalike suffix host is rejected'        => array( 'https://github.com.evil.example.com/plugin-hub.zip', false ),
			'lookalike prefix host is rejected'        => array( 'https://evilgithub.com/plugin-hub.zip', false ),
		);
	}

	#[DataProvider( 'repo_name_provider' )]
	public function test_is_valid_repo_name( string $name, bool $expected ): void {
		$this->assertSame( $expected, $this->call( 'is_valid_repo_name', array( $name ) ) );
	}

	public static function repo_name_provider(): array {
		return array(
			'simple name'         => array( 'plugin-hub', true ),
			'name with underscore' => array( 'my_plugin_2', true ),
			'empty string'         => array( '', false ),
			'path traversal'       => array( '../../etc/passwd', false ),
			'slash is rejected'    => array( 'org/repo', false ),
			'space is rejected'    => array( 'my plugin', false ),
		);
	}

	#[DataProvider( 'version_provider' )]
	public function test_is_valid_version( string $version, bool $expected ): void {
		$this->assertSame( $expected, $this->call( 'is_valid_version', array( $version ) ) );
	}

	public static function version_provider(): array {
		return array(
			'plain version'         => array( '1.4.0', true ),
			'v-prefixed is rejected' => array( 'v1.4.0', false ),
			'pre-release suffix'     => array( '1.4.0-beta.1', true ),
			'empty string'           => array( '', false ),
			'shell metacharacters'   => array( '1.0.0; rm -rf /', false ),
		);
	}
}
