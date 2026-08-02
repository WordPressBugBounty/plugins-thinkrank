<?php
/**
 * MCP connection self-test.
 *
 * @package ThinkRank\Mcp
 */

declare(strict_types=1);

namespace ThinkRank\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Exercises the MCP round trip the way an external client would and reports
 * *where* it broke, so an admin can tell a certificate problem from an
 * authentication problem from an ability-discovery problem without leaving the
 * MCP page (see #189).
 *
 * It performs one real loopback `tools/list` call against this site's own MCP
 * endpoint using the active connection token. The staged result names the first
 * failing step: `disabled` → `not_connected` → `unreachable` → `tls` →
 * `redirect` → `auth` → `no_tools` → `ok`.
 */
final class Mcp_Self_Test {

	/**
	 * Run the round-trip self-test.
	 *
	 * @return array<string, mixed>
	 */
	public static function run(): array {
		$endpoint = Mcp_Pairing::site_endpoint_fallback();

		$result = [
			'ok'            => false,
			'stage'         => '',
			'message'       => '',
			'endpoint'      => $endpoint,
			'mcp_enabled'   => Mcp_Manager::is_enabled(),
			'connected'     => Mcp_Pairing::is_connected(),
			'http_status'   => null,
			'redirected'    => false,
			'authenticated' => false,
			'tools_count'   => null,
		];

		if ( ! $result['mcp_enabled'] ) {
			$result['stage']   = 'disabled';
			$result['message'] = __( 'MCP access is turned off, so the endpoint refuses every request. Enable MCP access above and try again.', 'thinkrank' );
			return $result;
		}

		if ( ! $result['connected'] ) {
			$result['stage']   = 'not_connected';
			$result['message'] = __( 'No connection token exists yet. Click Connect to mint one, then run the test again.', 'thinkrank' );
			return $result;
		}

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout'     => 10,
				// Don't follow redirects: a 301/302 here IS the finding (the
				// classic http<->https scheme bounce), so surface it verbatim.
				'redirection' => 0,
				'headers'     => [
					'Authorization' => 'Bearer ' . Mcp_Pairing::site_token(),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body'        => wp_json_encode(
					[
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/list',
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			$err     = $response->get_error_message();
			$is_tls  = false !== stripos( $err, 'ssl' ) || false !== stripos( $err, 'certificate' );
			$result['stage']   = $is_tls ? 'tls' : 'unreachable';
			$result['message'] = $is_tls
				/* translators: %s: underlying transport error. */
				? sprintf( __( 'The endpoint could not be reached over HTTPS: %s. On local/dev sites this is usually a self-signed certificate the AI client must be told to trust.', 'thinkrank' ), $err )
				/* translators: %s: underlying transport error. */
				: sprintf( __( 'The endpoint could not be reached: %s.', 'thinkrank' ), $err );
			return $result;
		}

		$status                = (int) wp_remote_retrieve_response_code( $response );
		$result['http_status'] = $status;

		if ( in_array( $status, [ 301, 302, 307, 308 ], true ) ) {
			$location             = (string) wp_remote_retrieve_header( $response, 'location' );
			$result['redirected'] = true;
			$result['stage']      = 'redirect';
			$result['message']    = $location
				/* translators: %s: redirect target URL. */
				? sprintf( __( 'The endpoint redirected to %s instead of answering. A redirect between HTTP and HTTPS usually means the site address and WordPress address schemes disagree.', 'thinkrank' ), $location )
				: __( 'The endpoint redirected instead of answering, which usually means the site address and WordPress address schemes disagree.', 'thinkrank' );
			return $result;
		}

		if ( 401 === $status || 403 === $status ) {
			$result['stage']   = 'auth';
			$result['message'] = __( 'The endpoint rejected the connection token (authentication failed). Rotate the token and reconnect your AI client.', 'thinkrank' );
			return $result;
		}

		$result['authenticated'] = true;
		$body                    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$tools                   = ( is_array( $body ) && isset( $body['result']['tools'] ) && is_array( $body['result']['tools'] ) )
			? $body['result']['tools']
			: null;

		if ( 200 !== $status || null === $tools ) {
			$result['stage']   = 'no_tools';
			$result['message'] = __( 'The endpoint answered but returned no tool catalog. Confirm the MCP runtime is built and abilities are registered.', 'thinkrank' );
			$result['tools_count'] = is_array( $tools ) ? count( $tools ) : 0;
			return $result;
		}

		$result['ok']          = true;
		$result['stage']       = 'ok';
		$result['tools_count'] = count( $tools );
		$result['message']     = sprintf(
			/* translators: %d: number of MCP tools returned. */
			_n( 'Connection healthy: the endpoint authenticated and returned %d tool.', 'Connection healthy: the endpoint authenticated and returned %d tools.', $result['tools_count'], 'thinkrank' ),
			$result['tools_count']
		);
		return $result;
	}
}
