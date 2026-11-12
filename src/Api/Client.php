<?php
/**
 * Woodpecker REST API client built on the WordPress HTTP API.
 *
 * Every method returns its result or a WP_Error; nothing here throws.
 *
 * @package Shift8\Woodpecker
 */

namespace Shift8\Woodpecker\Api;

use Shift8\Woodpecker\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Client {

	private const TIMEOUT = 15;

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Start a manual pipeline for the configured repository and branch.
	 *
	 * @param string $message Commit/pipeline message shown in Woodpecker.
	 * @return Pipeline|\WP_Error
	 */
	public function triggerPipeline( string $message ) {
		$body = array(
			'branch'    => $this->config->branch(),
			'message'   => $message,
			// An empty object, not [], which Woodpecker would reject.
			'variables' => new \stdClass(),
		);
		if ( '' !== $this->config->workflow() ) {
			$body['workflow'] = $this->config->workflow();
		}

		$data = $this->request( 'POST', $this->repoPath( '/pipelines' ), $body );
		if ( is_wp_error( $data ) ) {
			return $this->rewrap( $data, 'shift8_woodpecker_trigger_rejected', __( 'Woodpecker did not accept the deployment.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		return $this->toPipeline( $data );
	}

	/**
	 * Fetch one specific pipeline by number.
	 *
	 * @param int $number Pipeline number.
	 * @return Pipeline|\WP_Error
	 */
	public function getPipeline( int $number ) {
		return $this->fetchPipeline( (string) $number );
	}

	/**
	 * @return Pipeline|\WP_Error
	 */
	public function getLatestPipeline() {
		return $this->fetchPipeline( 'latest' );
	}

	/**
	 * Names of workflows that can be started manually on the configured branch.
	 *
	 * @return string[]|\WP_Error
	 */
	public function getWorkflows() {
		$path = $this->repoPath( '/workflows' ) . '?branch=' . rawurlencode( $this->config->branch() );
		$data = $this->request( 'GET', $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$names = array();
		foreach ( $data as $item ) {
			if ( is_string( $item ) ) {
				$names[] = $item;
			} elseif ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) ) {
				$names[] = $item['name'];
			}
		}
		return $names;
	}

	/**
	 * Verify reachability, authentication, repository access and (optionally)
	 * the workflow. Never starts a pipeline.
	 *
	 * @return string[]|\WP_Error Human readable result lines.
	 */
	public function testConnection() {
		$valid = $this->config->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$user = $this->request( 'GET', '/api/user' );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$lines = array(
			__( 'Connection successful.', 'shift8-deployment-manager-for-woodpecker-ci' ),
			__( 'Authenticated with Woodpecker.', 'shift8-deployment-manager-for-woodpecker-ci' ),
		);

		$repo = $this->request( 'GET', $this->repoPath( '' ) );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}
		/* translators: %d: Woodpecker repository ID. */
		$lines[] = sprintf( __( 'Repository %d accessible.', 'shift8-deployment-manager-for-woodpecker-ci' ), $this->config->repoId() );

		$workflow = $this->config->workflow();
		if ( '' !== $workflow ) {
			$workflows = $this->getWorkflows();
			if ( is_wp_error( $workflows ) ) {
				/* translators: %s: workflow name. */
				$lines[] = sprintf( __( 'Could not verify workflow "%s": the workflow list is unavailable on this Woodpecker version or branch.', 'shift8-deployment-manager-for-woodpecker-ci' ), $workflow );
			} elseif ( in_array( $workflow, $workflows, true ) ) {
				/* translators: %s: workflow name. */
				$lines[] = sprintf( __( 'Workflow "%s" available.', 'shift8-deployment-manager-for-woodpecker-ci' ), $workflow );
			} else {
				return new \WP_Error(
					'shift8_woodpecker_workflow_missing',
					/* translators: %s: workflow name. */
					sprintf( __( 'Workflow "%s" was not found on the configured branch.', 'shift8-deployment-manager-for-woodpecker-ci' ), $workflow )
				);
			}
		}
		return $lines;
	}

	/**
	 * @param string $selector Pipeline number or "latest".
	 * @return Pipeline|\WP_Error
	 */
	private function fetchPipeline( string $selector ) {
		$data = $this->request( 'GET', $this->repoPath( '/pipelines/' . $selector ) );
		if ( is_wp_error( $data ) ) {
			return $this->rewrap( $data, 'shift8_woodpecker_status_failed', __( 'Could not retrieve the pipeline status from Woodpecker.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		return $this->toPipeline( $data );
	}

	/**
	 * @param mixed $data Decoded response.
	 * @return Pipeline|\WP_Error
	 */
	private function toPipeline( $data ) {
		$pipeline = Pipeline::fromArray( $data );
		if ( null === $pipeline ) {
			return new \WP_Error( 'shift8_woodpecker_invalid_response', __( 'Woodpecker returned a response that does not look like a pipeline.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		return $pipeline;
	}

	private function repoPath( string $suffix ): string {
		return '/api/repos/' . $this->config->repoId() . $suffix;
	}

	/**
	 * Keep auth/network errors as-is (they are already actionable) and wrap
	 * everything else with an operation-specific code and message.
	 *
	 * @param \WP_Error $error   Original error.
	 * @param string    $code    Replacement error code.
	 * @param string    $message Operation summary.
	 */
	private function rewrap( \WP_Error $error, string $code, string $message ): \WP_Error {
		$passthrough = array( 'shift8_woodpecker_config', 'shift8_woodpecker_http', 'shift8_woodpecker_auth', 'shift8_woodpecker_forbidden', 'shift8_woodpecker_not_found' );
		if ( in_array( $error->get_error_code(), $passthrough, true ) ) {
			return $error;
		}
		return new \WP_Error( $code, $message . ' ' . $error->get_error_message() );
	}

	/**
	 * The single place where HTTP requests are made.
	 *
	 * @param string            $method GET or POST.
	 * @param string            $path   Path (with optional query) below the configured base URL.
	 * @param array<mixed>|null $body   JSON body for POST requests.
	 * @return array<mixed>|\WP_Error Decoded JSON (empty array for empty bodies).
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$valid = $this->config->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$args = array(
			'timeout'     => self::TIMEOUT,
			// Never follow redirects: the bearer token must only reach the configured origin.
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $this->config->token(),
				'Accept'        => 'application/json',
			),
		);
		$url  = $this->config->baseUrl() . $path;

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = null === $body ? '' : wp_json_encode( $body );
			$response                        = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'shift8_woodpecker_http',
				sprintf(
					/* translators: %s: error message from the HTTP layer. */
					__( 'Could not reach Woodpecker: %s', 'shift8-deployment-manager-for-woodpecker-ci' ),
					$this->scrub( $response->get_error_message() )
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status > 299 ) {
			return $this->statusError( $status, $raw );
		}

		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'shift8_woodpecker_invalid_response', __( 'Woodpecker returned a response that is not valid JSON.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}
		return $decoded;
	}

	private function statusError( int $status, string $raw ): \WP_Error {
		switch ( $status ) {
			case 401:
				return new \WP_Error( 'shift8_woodpecker_auth', __( 'Woodpecker rejected the API token (HTTP 401). Check that the token is valid and has not expired.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
			case 403:
				return new \WP_Error( 'shift8_woodpecker_forbidden', __( 'The Woodpecker token is not permitted to perform this action (HTTP 403).', 'shift8-deployment-manager-for-woodpecker-ci' ) );
			case 404:
				return new \WP_Error( 'shift8_woodpecker_not_found', __( 'Woodpecker could not find the requested repository or pipeline (HTTP 404). Check the repository ID.', 'shift8-deployment-manager-for-woodpecker-ci' ) );
		}

		$detail = '';
		$data   = json_decode( $raw, true );
		if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) {
			$detail = $this->scrub( $data['message'] );
		}
		return new \WP_Error(
			'shift8_woodpecker_http_status',
			trim(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Woodpecker returned HTTP %d.', 'shift8-deployment-manager-for-woodpecker-ci' ),
					$status
				) . ' ' . $detail
			)
		);
	}

	/**
	 * Remote text is untrusted: strip markup, bound its length and make sure
	 * the token can never be echoed back through an error message.
	 *
	 * @param string $text Remote text.
	 */
	private function scrub( string $text ): string {
		$text  = wp_strip_all_tags( $text );
		$token = $this->config->token();
		if ( '' !== $token ) {
			$text = str_replace( $token, '[redacted]', $text );
		}
		return mb_substr( $text, 0, 200 );
	}
}
