<?php
/**
 * Unit tests for the pure logic of PluginHub\MU_Plugins.
 *
 * @package PluginHub
 */

namespace PluginHub {
	if ( ! function_exists( __NAMESPACE__ . '\\untrailingslashit' ) ) {
		function untrailingslashit( $value ) {
			return rtrim( $value, '/\\' );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_mkdir_p' ) ) {
		function wp_mkdir_p( $dir ) {
			return is_dir( $dir ) || mkdir( $dir, 0755, true );
		}
		function wp_is_writable( $path ) {
			return is_writable( $path );
		}
		function wp_delete_file( $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		function wp_generate_password( $length = 12, $special = true ) {
			return substr( md5( uniqid( '', true ) ), 0, $length );
		}
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\MU_Plugins;

	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';

	final class MuPluginsTest extends TestCase {

		public static function valid_paths(): array {
			return array(
				'category plugin' => array( 'mu-plugins/security/two-factor.php', 'security', 'two-factor.php' ),
				'helpers'         => array( 'mu-plugins/000-helpers.php', '', '000-helpers.php' ),
			);
		}

		#[DataProvider( 'valid_paths' )]
		public function test_valid_paths_are_accepted( string $path, string $category, string $file ): void {
			$this->assertSame(
				array(
					'category' => $category,
					'file'     => $file,
				),
				MU_Plugins::parse_catalog_path( $path )
			);
		}

		public static function invalid_paths(): array {
			return array(
				'traversal'        => array( 'mu-plugins/../wp-config.php' ),
				'traversal in dir' => array( 'mu-plugins/security/../../x.php' ),
				'autoloader'       => array( 'mu-plugins/000-autoloader.php' ),
				'index'            => array( 'mu-plugins/index.php' ),
				'nested'           => array( 'mu-plugins/a/b/c.php' ),
				'top-level other'  => array( 'mu-plugins/other.php' ),
				'helpers in dir'   => array( 'mu-plugins/admin/000-helpers.php' ),
				'wrong root'       => array( 'scripts/check.php' ),
				'not php'          => array( 'mu-plugins/admin/readme.md' ),
				'uppercase'        => array( 'mu-plugins/Admin/File.php' ),
				'null byte'        => array( "mu-plugins/admin/a.php\0.txt" ),
				'space'            => array( 'mu-plugins/admin/a b.php' ),
			);
		}

		#[DataProvider( 'invalid_paths' )]
		public function test_invalid_paths_are_rejected( string $path ): void {
			$this->assertFalse( MU_Plugins::parse_catalog_path( $path ) );
		}

		public function test_parse_tree_filters_and_keys_by_basename(): void {
			$sha  = str_repeat( 'a', 40 );
			$tree = array(
				array( 'type' => 'blob', 'path' => 'mu-plugins/admin/x.php', 'sha' => $sha ),
				array( 'type' => 'blob', 'path' => 'mu-plugins/security/x.php', 'sha' => $sha ),
				array( 'type' => 'tree', 'path' => 'mu-plugins/admin', 'sha' => $sha ),
				array( 'type' => 'blob', 'path' => 'mu-plugins/000-autoloader.php', 'sha' => $sha ),
				array( 'type' => 'blob', 'path' => 'mu-plugins/cleanup/y.php', 'sha' => 'nothex' ),
			);
			$catalog = MU_Plugins::parse_tree( $tree );
			$this->assertSame( array( 'x.php' ), array_keys( $catalog ) );
			$this->assertSame( 'admin', $catalog['x.php']['category'] );
		}

		public function test_header_parsing_accepts_lowercase_field_names(): void {
			$code = "<?php\n/**\n * Plugin name: Thing\n * version: 1.2.3\n */\n";
			$this->assertSame(
				array(
					'name'    => 'Thing',
					'version' => '1.2.3',
				),
				MU_Plugins::parse_header( $code )
			);
			$this->assertSame( '', MU_Plugins::get_header_field( "<?php\n// nothing", 'Version' ) );
		}

		public function test_header_is_only_read_from_the_file_start(): void {
			$code = "<?php\n" . str_repeat( ' ', 9000 ) . "\n * Version: 9.9.9\n";
			$this->assertSame( '', MU_Plugins::get_header_field( $code, 'Version' ) );
		}

		public static function update_cases(): array {
			return array(
				'newer'         => array( '1.0.0', '1.0.1', true ),
				'numeric not string' => array( '1.9.0', '1.10.0', true ),
				'same'          => array( '1.0.0', '1.0.0', false ),
				'older'         => array( '2.0.0', '1.9.9', false ),
				'bad remote'    => array( '1.0.0', 'latest', false ),
				'bad local'     => array( '', '1.0.0', false ),
			);
		}

		#[DataProvider( 'update_cases' )]
		public function test_update_available( string $local, string $remote, bool $expected ): void {
			$this->assertSame( $expected, MU_Plugins::is_update_available( $local, $remote ) );
		}

		public function test_blob_sha_matches_git(): void {
			// `printf 'hello\n' | git hash-object --stdin`.
			$this->assertSame( 'ce013625030ba8dba906f756967f9e9ca394464a', MU_Plugins::blob_sha( "hello\n" ) );
		}

		public function test_helper_detection(): void {
			$this->assertTrue( MU_Plugins::needs_helpers( "<?php\n\$ip = mu_get_client_ip();" ) );
			$this->assertTrue( MU_Plugins::needs_helpers( "<?php\nif ( mu_config ('x', 1) ) {}" ) );
			$this->assertFalse( MU_Plugins::needs_helpers( "<?php\nadd_action('init', 'wp_x');" ) );
		}

		public function test_helper_detection_ignores_comments_strings_methods_and_local_functions(): void {
			$this->assertFalse( MU_Plugins::needs_helpers( "<?php\n// mu_log('x');\n/* mu_alert() */\n\$s = 'mu_config(1)';\n\$t = \"mu_log(1)\";" ) );
			$this->assertFalse( MU_Plugins::needs_helpers( "<?php\n\$o->mu_log(1); Foo::mu_log(2); new mu_thing();" ) );
			$this->assertFalse( MU_Plugins::needs_helpers( "<?php\nfunction mu_get_user_ip() { return 1; }\n\$a = mu_get_user_ip();" ) );
			$this->assertFalse( MU_Plugins::needs_helpers( "<?php\nfunction &mu_ref() { return \$x; }\nmu_ref();" ) );
			$this->assertTrue( MU_Plugins::needs_helpers( "<?php\nfunction mu_local() {}\nmu_local(); mu_log('x');" ) );
		}

		public function test_source_sanity_checks(): void {
			$ok = "<?php\n/**\n * Plugin Name: A\n * Version: 1.0.0\n */\n\$a = 1;\n";
			$this->assertSame( '', MU_Plugins::check_source( $ok ) );
			$this->assertSame( 'not_php', MU_Plugins::check_source( "\n" . $ok ) );
			$this->assertSame( 'not_php', MU_Plugins::check_source( '<html>404</html>' ) );
			$this->assertSame( 'no_name', MU_Plugins::check_source( "<?php\n/* Version: 1.0.0 */" ) );
			$this->assertSame( 'bad_version', MU_Plugins::check_source( "<?php\n/* Plugin Name: A\n Version: abc */" ) );
			$this->assertSame( 'syntax', MU_Plugins::check_source( $ok . "function (" ) );
		}

		public function test_file_name_validation(): void {
			$this->assertTrue( MU_Plugins::is_valid_file_name( 'disable-comments.php' ) );
			$this->assertFalse( MU_Plugins::is_valid_file_name( '../x.php' ) );
			$this->assertFalse( MU_Plugins::is_valid_file_name( 'a/b.php' ) );
			$this->assertFalse( MU_Plugins::is_valid_file_name( 'x.txt' ) );
		}

		private function write_atomic( string $dest, string $contents ) {
			$method = new \ReflectionMethod( MU_Plugins::class, 'write_atomic' );
			$object = ( new \ReflectionClass( MU_Plugins::class ) )->newInstanceWithoutConstructor();
			return $method->invoke( $object, $dest, $contents );
		}

		public function test_write_replaces_file_and_removes_backup(): void {
			$dir  = sys_get_temp_dir() . '/plugin-hub-mu-' . uniqid();
			$dest = $dir . '/example.php';
			mkdir( $dir );
			file_put_contents( $dest, "<?php\n// old\n" );

			$this->assertTrue( $this->write_atomic( $dest, "<?php\n// new\n" ) );
			$this->assertSame( "<?php\n// new\n", file_get_contents( $dest ) );
			$this->assertFileDoesNotExist( $dest . '.bak' );
			$this->assertSame( array( '.', '..', 'example.php' ), scandir( $dir ) );

			unlink( $dest );
			rmdir( $dir );
		}

		public function test_write_creates_new_file_without_backup(): void {
			$dir  = sys_get_temp_dir() . '/plugin-hub-mu-' . uniqid();
			$dest = $dir . '/fresh.php';

			$this->assertTrue( $this->write_atomic( $dest, "<?php\n" ) );
			$this->assertFileExists( $dest );
			$this->assertFileDoesNotExist( $dest . '.bak' );

			unlink( $dest );
			rmdir( $dir );
		}
	}
}
