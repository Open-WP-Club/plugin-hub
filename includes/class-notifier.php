<?php
/**
 * Update notifications by e-mail and webhook.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.8.0
 */

namespace PluginHub;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-settings.php';

/**
 * Notifier class.
 *
 * @since 1.8.0
 */
class Notifier {

	/**
	 * Decide whether a report is worth sending.
	 *
	 * @since  1.8.0
	 * @param  string $mode      'all' or 'failures'.
	 * @param  array  $updated   Updated items.
	 * @param  array  $available Items with a pending update.
	 * @param  array  $failed    Failed items, including automatic rollbacks.
	 * @return bool
	 */
	public static function should_notify( $mode, array $updated, array $available, array $failed ) {
		if ( 'failures' === $mode ) {
			return ! empty( $failed );
		}

		return ! empty( $updated ) || ! empty( $available ) || ! empty( $failed );
	}

	/**
	 * Detect the webhook payload format from its URL.
	 *
	 * @since  1.8.0
	 * @param  string $url Webhook URL.
	 * @return string      'slack', 'discord' or 'generic'.
	 */
	public static function detect_format( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( 'hooks.slack.com' === $host ) {
			return 'slack';
		}

		if ( in_array( $host, array( 'discord.com', 'discordapp.com' ), true ) && 0 === strpos( $path, '/api/webhooks' ) ) {
			return 'discord';
		}

		return 'generic';
	}

	/**
	 * Build a webhook payload.
	 *
	 * @since  1.8.0
	 * @param  string $format  Payload format.
	 * @param  string $subject Message subject.
	 * @param  string $body    Message body.
	 * @return array
	 */
	public static function build_payload( $format, $subject, $body ) {
		if ( 'slack' === $format ) {
			return array( 'text' => '*' . $subject . "*\n" . $body );
		}

		if ( 'discord' === $format ) {
			$content = '**' . $subject . "**\n" . $body;
			$content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 1900 ) : substr( $content, 0, 1900 );

			return array( 'content' => $content );
		}

		return array(
			'subject' => $subject,
			'text'    => $body,
			'site'    => get_bloginfo( 'name' ),
			'url'     => home_url( '/' ),
		);
	}

	/**
	 * Valid recipient addresses, falling back to the admin e-mail.
	 *
	 * @since  1.8.0
	 * @return string[]
	 */
	public static function recipients() {
		$list   = preg_split( '/[\s,;]+/', (string) Settings::get( 'notify_emails' ), -1, PREG_SPLIT_NO_EMPTY );
		$emails = array_values(
			array_unique(
				array_filter(
					(array) $list,
					static function ( $email ) {
						return (bool) is_email( $email );
					}
				)
			)
		);

		if ( empty( $emails ) ) {
			$admin = (string) get_option( 'admin_email' );
			if ( is_email( $admin ) ) {
				$emails[] = $admin;
			}
		}

		return $emails;
	}

	/**
	 * Post the message to the configured webhook.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  string $subject Message subject.
	 * @param  string $body    Message body.
	 * @return bool|null       Null when no webhook is configured.
	 */
	private static function send_webhook( $subject, $body ) {
		$url = (string) Settings::get( 'webhook_url' );
		if ( '' === $url || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return null;
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( self::build_payload( self::detect_format( $url ), $subject, $body ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Send the message to every configured channel.
	 *
	 * @since  1.8.0
	 * @param  string $subject Message subject.
	 * @param  string $body    Message body.
	 * @return bool            True if at least one channel accepted it.
	 */
	public static function send( $subject, $body ) {
		$mail    = false;
		$emails  = self::recipients();
		$webhook = self::send_webhook( $subject, $body );

		if ( $emails ) {
			$mail = (bool) wp_mail( $emails, $subject, $body );
		}

		return $mail || true === $webhook;
	}

	/**
	 * Send a test message to every configured channel.
	 *
	 * @since  1.8.0
	 * @return array array( 'mail' => bool, 'webhook' => bool|null ).
	 */
	public static function send_test() {
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Plugin Hub test notification', 'plugin-hub' ), get_bloginfo( 'name' ) );
		$body    = __( 'This is a test message from Plugin Hub. Your notification settings work.', 'plugin-hub' );
		$emails  = self::recipients();

		return array(
			'mail'    => $emails ? (bool) wp_mail( $emails, $subject, $body ) : false,
			'webhook' => self::send_webhook( $subject, $body ),
		);
	}
}
