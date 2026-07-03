<?php
/**
 * AI provider adapter.
 *
 * @package Gutenwright
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls an OpenAI-compatible chat completions API.
 */
class Gutenwright_AI_Provider {
	/**
	 * Settings accessor.
	 *
	 * @var Gutenwright_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Gutenwright_Settings $settings Settings accessor.
	 */
	public function __construct( Gutenwright_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Generate Gutenberg block output from a prompt and post context.
	 *
	 * @param string $prompt  User prompt.
	 * @param array  $context Post/editor context.
	 * @return array|WP_Error
	 */
	public function generate( $prompt, array $context ) {
		$options = $this->settings->get_options();

		if ( empty( $options['api_key'] ) ) {
			return new WP_Error(
				'gutenwright_missing_api_key',
				__( 'Missing AI API key. Configure Gutenwright settings first.', 'gutenwright' ),
				array( 'status' => 400 )
			);
		}

		$payload = array(
			'model'    => $options['model'],
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $this->get_system_prompt(),
				),
				array(
					'role'    => 'user',
					'content' => $this->get_user_prompt( $prompt, $context ),
				),
			),
		);

		$response_format = $this->build_response_format( $options );

		if ( null !== $response_format ) {
			$payload['response_format'] = $response_format;
		}

		$response = wp_remote_post(
			$options['api_endpoint'],
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $options['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'AI provider request failed.', 'gutenwright' );

			return new WP_Error(
				'gutenwright_provider_error',
				$message,
				array( 'status' => 502 )
			);
		}

		$content = isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '';
		$result  = $this->decode_json_content( $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalize_result( $result );
	}

	/**
	 * Stream Gutenberg block output from a prompt and post context.
	 *
	 * @param string   $prompt   User prompt.
	 * @param array    $context  Post/editor context.
	 * @param callable $on_token Token callback.
	 * @return array|WP_Error
	 */
	public function stream_generate( $prompt, array $context, callable $on_token ) {
		$options = $this->settings->get_options();

		if ( empty( $options['api_key'] ) ) {
			return new WP_Error(
				'gutenwright_missing_api_key',
				__( 'Missing AI API key. Configure Gutenwright settings first.', 'gutenwright' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error(
				'gutenwright_missing_curl',
				__( 'PHP cURL is required for streaming AI responses.', 'gutenwright' ),
				array( 'status' => 500 )
			);
		}

		$content = '';
		$error   = null;
		$pending = '';
		$raw     = '';
		$payload = array(
			'model'    => $options['model'],
			'stream'   => true,
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $this->get_system_prompt(),
				),
				array(
					'role'    => 'user',
					'content' => $this->get_user_prompt( $prompt, $context ),
				),
			),
		);

		$response_format = $this->build_response_format( $options );

		if ( null !== $response_format ) {
			$payload['response_format'] = $response_format;
		}

		$curl = curl_init( $options['api_endpoint'] );

		$curl_options = array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => array(
				'Authorization: Bearer ' . $options['api_key'],
				'Content-Type: application/json',
				'Accept: text/event-stream',
			),
			CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 90,
			CURLOPT_BUFFERSIZE     => 128,
			CURLOPT_WRITEFUNCTION  => function ( $curl_handle, $chunk ) use ( &$content, &$error, &$pending, &$raw, $on_token ) {
					$pending .= $chunk;
					$raw     .= $chunk;

					while ( false !== strpos( $pending, "\n" ) ) {
						list( $line, $pending ) = explode( "\n", $pending, 2 );
						$line                  = trim( $line );

						if ( '' === $line || 0 === strpos( $line, ':' ) ) {
							continue;
						}

						if ( 0 !== strpos( $line, 'data:' ) ) {
							continue;
						}

						$data = trim( substr( $line, 5 ) );

						if ( '[DONE]' === $data ) {
							continue;
						}

						$decoded = json_decode( $data, true );

						if ( isset( $decoded['error']['message'] ) ) {
							$error = $decoded['error']['message'];
							continue;
						}

						$token = isset( $decoded['choices'][0]['delta']['content'] ) ? $decoded['choices'][0]['delta']['content'] : '';

						if ( '' !== $token ) {
							$content .= $token;
							$on_token( $token );
						}
					}

					return strlen( $chunk );
				},
		);

		$ca_bundle = $this->get_ca_bundle_path();

		if ( $ca_bundle ) {
			$curl_options[ CURLOPT_CAINFO ] = $ca_bundle;
		}

		if ( defined( 'CURL_HTTP_VERSION_1_1' ) ) {
			$curl_options[ CURLOPT_HTTP_VERSION ] = CURL_HTTP_VERSION_1_1;
		}

		curl_setopt_array( $curl, $curl_options );

		$success     = curl_exec( $curl );
		$curl_error  = curl_error( $curl );
		$status_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );

		if ( false === $success ) {
			return new WP_Error(
				'gutenwright_stream_transport_error',
				$curl_error ? $curl_error : __( 'AI streaming request failed.', 'gutenwright' ),
				array( 'status' => 502 )
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			if ( ! $error ) {
				$decoded_body = json_decode( $raw, true );

				if ( isset( $decoded_body['error']['message'] ) ) {
					$error = (string) $decoded_body['error']['message'];
				} elseif ( '' !== trim( $raw ) ) {
					$error = sprintf(
						/* translators: 1: HTTP status code, 2: raw response body. */
						__( 'AI provider returned HTTP %1$d: %2$s', 'gutenwright' ),
						(int) $status_code,
						$this->truncate( $raw, 500 )
					);
				}
			}

			return new WP_Error(
				'gutenwright_provider_error',
				$error ? $error : __( 'AI provider streaming request failed.', 'gutenwright' ),
				array( 'status' => 502 )
			);
		}

		if ( $error ) {
			return new WP_Error(
				'gutenwright_provider_error',
				$error,
				array( 'status' => 502 )
			);
		}

		$result = $this->decode_json_content( $content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->normalize_result( $result );
	}

	/**
	 * Check whether image generation is enabled.
	 *
	 * @return bool
	 */
	public function is_image_generation_enabled() {
		$options = $this->settings->get_options();

		return ! empty( $options['enable_image_generation'] );
	}

	/**
	 * Generate an image using the OpenAI Images API.
	 *
	 * @param string $prompt Image prompt.
	 * @return array|WP_Error
	 */
	public function generate_image( $prompt ) {
		$options = $this->settings->get_options();

		if ( empty( $options['enable_image_generation'] ) ) {
			return new WP_Error(
				'gutenwright_image_generation_disabled',
				__( 'Image generation is disabled in Gutenwright settings.', 'gutenwright' )
			);
		}

		if ( empty( $options['api_key'] ) ) {
			return new WP_Error(
				'gutenwright_missing_api_key',
				__( 'Missing AI API key. Configure Gutenwright settings first.', 'gutenwright' )
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error(
				'gutenwright_missing_curl',
				__( 'PHP cURL is required for image generation.', 'gutenwright' )
			);
		}

		$payload = wp_json_encode(
			array(
				'model'  => $options['image_model'],
				'prompt' => $prompt,
				'size'   => $options['image_size'],
			)
		);

		$curl         = curl_init( 'https://api.openai.com/v1/images/generations' );
		$curl_options = array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => array(
				'Authorization: Bearer ' . $options['api_key'],
				'Content-Type: application/json',
			),
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 300,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_LOW_SPEED_LIMIT => 0,
			CURLOPT_LOW_SPEED_TIME  => 0,
		);

		$ca_bundle = $this->get_ca_bundle_path();

		if ( $ca_bundle ) {
			$curl_options[ CURLOPT_CAINFO ] = $ca_bundle;
		}

		curl_setopt_array( $curl, $curl_options );

		$raw_body    = curl_exec( $curl );
		$curl_error  = curl_error( $curl );
		$status_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );

		if ( false === $raw_body ) {
			return new WP_Error(
				'gutenwright_image_transport_error',
				$curl_error ? $curl_error : __( 'Image generation request failed.', 'gutenwright' )
			);
		}

		$body = json_decode( (string) $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Image generation request failed.', 'gutenwright' );

			return new WP_Error(
				'gutenwright_image_provider_error',
				$message
			);
		}

		$image_base64 = isset( $body['data'][0]['b64_json'] ) ? $body['data'][0]['b64_json'] : '';
		$image_url    = isset( $body['data'][0]['url'] ) ? esc_url_raw( $body['data'][0]['url'] ) : '';

		if ( '' === $image_base64 && $image_url ) {
			return array(
				'url' => $image_url,
			);
		}

		if ( '' === $image_base64 ) {
			return new WP_Error(
				'gutenwright_missing_image_data',
				__( 'Image generation did not return image data.', 'gutenwright' )
			);
		}

		$bytes = base64_decode( $image_base64, true );

		if ( false === $bytes ) {
			return new WP_Error(
				'gutenwright_invalid_image_data',
				__( 'Image generation returned invalid image data.', 'gutenwright' )
			);
		}

		return array(
			'bytes'     => $bytes,
			'extension' => 'png',
			'mime_type' => 'image/png',
		);
	}

	/**
	 * Build the response_format payload based on plugin settings.
	 *
	 * @param array $options Resolved plugin options.
	 * @return array|null
	 */
	private function build_response_format( array $options ) {
		$mode = isset( $options['resolved_response_format'] ) ? (string) $options['resolved_response_format'] : ( $options['response_format'] ?? 'json_object' );

		if ( 'none' === $mode ) {
			return null;
		}

		if ( 'json_schema' === $mode ) {
			return array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'gutenwright_block_output',
					'strict' => true,
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'summary', 'serializedBlocks', 'assets', 'warnings' ),
						'properties'           => array(
							'summary'          => array( 'type' => 'string' ),
							'serializedBlocks' => array( 'type' => 'string' ),
							'assets'           => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array( 'type', 'placeholder', 'filename', 'prompt' ),
									'properties'           => array(
										'type'        => array( 'type' => 'string' ),
										'placeholder' => array( 'type' => 'string' ),
										'filename'    => array( 'type' => 'string' ),
										'prompt'      => array( 'type' => 'string' ),
										'url'         => array( 'type' => 'string' ),
									),
								),
							),
							'warnings'         => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
			);
		}

		return array( 'type' => 'json_object' );
	}

	/**
	 * Get WordPress's bundled CA bundle for cURL streaming requests.
	 *
	 * @return string
	 */
	private function get_ca_bundle_path() {
		$paths = array(
			ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			ABSPATH . WPINC . '/certificates/ca-bundle.pem',
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/**
	 * System prompt sent from PHP backend.
	 *
	 * @return string
	 */
	private function get_system_prompt() {
		$options = $this->settings->get_options();
		$prompt  = <<<'PROMPT'
You generate safe Gutenberg-compatible block output for WordPress editors.

Return JSON only. Do not wrap the JSON in markdown. The JSON schema is:
{
  "summary": "Short editor-facing summary",
  "serializedBlocks": "<!-- wp:paragraph --><p>Valid serialized Gutenberg block markup</p><!-- /wp:paragraph -->",
  "assets": [],
  "warnings": []
}

Use the user prompt and current post context to decide the most appropriate output type. You may generate any suitable Gutenberg-compatible content, not only images or charts. Valid examples include custom HTML, inline SVG, org charts, diagrams, timelines, data visualizations, tables, callouts, quotes, FAQs, comparison sections, multi-column layouts, images, embeds, lists, headings, interactive games, calculators, and rich article sections.

If the context includes existingBlock, treat this as an edit/refinement request. Use existingBlock.serialized and existingBlock.code as the source material, preserve useful existing code and behavior, apply the requested changes, and return the complete updated replacement block. Do not return a diff or partial patch.

Prefer registered WordPress core blocks and serialized Gutenberg markup over unsupported custom block names. Useful core blocks include core/paragraph, core/heading, core/group, core/columns, core/column, core/html, core/table, core/image, core/list, core/quote, core/details, core/buttons, core/button, core/separator, core/spacer, and core/embed.

Use core/html for simple/static self-contained custom HTML, SVG, org charts, diagrams, timelines, and visual structures that do not need JavaScript or style tags. When using core/html, include {"preview":true} in the block attributes so the editor opens the block in preview mode by default. SVG and HTML must be accessible, self-contained, and free of scripts, event handlers, external network calls, forms, iframes unless explicitly requested, and unsafe attributes. Include labels, titles, captions, or aria attributes where useful.

If you cannot safely satisfy part of the request, still return valid serializedBlocks for the safe portion and include a warning. Never include explanations outside the JSON object.
PROMPT;

		if ( function_exists( 'gutenwright_is_sandbox_generation_disallowed' ) && gutenwright_is_sandbox_generation_disallowed() ) {
			$prompt .= "\n\nSandboxed AI HTML is disabled because DISALLOW_UNFILTERED_HTML is enabled for this site. Do not return gutenwright/sandbox blocks. Do not return core/html that contains scripts, style tags, scoped CSS, event handlers, iframes, stateful controls, or games. If the user asks for interactive or scripted content, return a safe static alternative and include a warning that sandboxed interactive output is disabled by site policy.";
		} elseif ( ! empty( $options['seamless_mode'] ) ) {
			$prompt .= "\n\nSeamless mode is enabled. For content that requires JavaScript, script tags, page-level styles, event handlers, state, animation, full-page visual effects such as snow or confetti, or interactive widgets, use the custom Gutenwright block. In this mode the block is injected directly into the WordPress page, not isolated in an iframe, so HTML, CSS, and JavaScript may intentionally affect the current page document. Use this serialized block shape:\n<!-- wp:gutenwright/sandbox {\"title\":\"Short title\",\"height\":640,\"html\":\"<main id=\\\"app\\\"></main>\",\"css\":\"body{padding:24px;} .snowflake{position:fixed;}\",\"js\":\"const app=document.getElementById('app');\"} /-->\nKeep generated code self-contained and resilient. Use unique ids/classes to avoid accidental collisions. Do not use external network calls, remote scripts, remote stylesheets, cookies, localStorage, parent/window.top access, or navigation.";
		} else {
			$prompt .= "\n\nFor content that requires JavaScript, script tags, style tags, scoped CSS, event handlers, state, buttons, keyboard interaction, or games such as tic-tac-toe, use the custom sandbox block instead of core/html. The sandbox block is rendered inside an isolated iframe with sandbox=\"allow-scripts\", no same-origin access, no parent DOM access, and no plugin/page style leakage. Use this serialized block shape:\n<!-- wp:gutenwright/sandbox {\"title\":\"Short title\",\"height\":640,\"html\":\"<main id=\\\"app\\\"></main>\",\"css\":\"body{padding:24px;}\",\"js\":\"const app=document.getElementById('app');\"} /-->\nKeep sandbox HTML, CSS, and JS self-contained. Choose a height large enough for the full UI so the iframe does not need vertical scrolling. Do not use external network calls, remote scripts, remote stylesheets, cookies, localStorage, parent/window.top access, or navigation. Prefer event listeners in JS over inline event handler attributes.";
		}

		if ( ! empty( $options['enable_image_generation'] ) ) {
			$prompt .= "\n\nImage generation is enabled. When the user asks for a generated image, return a core/image block whose url uses a unique placeholder like GUTENWRIGHT_IMAGE_1, and add a matching asset object: {\"type\":\"image\",\"placeholder\":\"GUTENWRIGHT_IMAGE_1\",\"filename\":\"descriptive-name.png\",\"prompt\":\"Detailed prompt for the image generation model\"}. The PHP backend will generate the image first, import it into the WordPress Media Library, replace the placeholder with the real Media Library URL, and attach the generated attachment ID to the core/image block before returning it to the editor.";
		} else {
			$prompt .= "\n\nImage generation is disabled. If the user asks for a generated image, do not invent image URLs. Return a warning and offer a non-image block alternative when useful.";
		}

		return $prompt;
	}

	/**
	 * Build bounded user prompt with post context.
	 *
	 * @param string $prompt  User prompt.
	 * @param array  $context Post/editor context.
	 * @return string
	 */
	private function get_user_prompt( $prompt, array $context ) {
		$context_json = wp_json_encode(
			$this->limit_context( $context ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return "User prompt:\n" . $prompt . "\n\nCurrent WordPress post/editor context:\n" . $context_json;
	}

	/**
	 * Keep context compact before sending it to the model.
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	private function limit_context( array $context ) {
		$limited = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $value ) ) {
				$limited[ $key ] = $this->truncate( wp_strip_all_tags( $value ), 12000 );
				continue;
			}

			if ( is_array( $value ) ) {
				$limited[ $key ] = $this->limit_context( $value );
				continue;
			}

			$limited[ $key ] = $value;
		}

		return $limited;
	}

	/**
	 * Truncate a string without requiring mbstring.
	 *
	 * @param string $value  Text.
	 * @param int    $length Max length.
	 * @return string
	 */
	private function truncate( $value, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Decode JSON returned by the model.
	 *
	 * @param string $content Raw model content.
	 * @return array|WP_Error
	 */
	private function decode_json_content( $content ) {
		$content = trim( $content );

		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches ) ) {
			$content = $matches[1];
		}

		$decoded = json_decode( $content, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'gutenwright_invalid_provider_json',
				__( 'AI provider returned invalid JSON.', 'gutenwright' ),
				array( 'status' => 502 )
			);
		}

		return $decoded;
	}

	/**
	 * Normalize model result fields.
	 *
	 * @param array $result Raw decoded result.
	 * @return array
	 */
	private function normalize_result( array $result ) {
		return array(
			'summary'          => isset( $result['summary'] ) ? sanitize_text_field( $result['summary'] ) : '',
			'serializedBlocks' => isset( $result['serializedBlocks'] ) ? (string) $result['serializedBlocks'] : '',
			'assets'           => isset( $result['assets'] ) && is_array( $result['assets'] ) ? $result['assets'] : array(),
			'warnings'         => isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? array_values( array_map( 'sanitize_text_field', $result['warnings'] ) ) : array(),
		);
	}
}
