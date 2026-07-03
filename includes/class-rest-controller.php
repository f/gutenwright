<?php
/**
 * REST API controller.
 *
 * @package Gutenwright
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles editor generation requests.
 */
class Gutenwright_REST_Controller {
	const NAMESPACE            = 'gutenwright/v1';
	const LEGACY_NAMESPACE     = 'pressmind/v1';
	const ROUTE                = '/generate';
	const STREAM_ROUTE         = '/generate-stream';
	const SANDBOX_BLOCK        = 'gutenwright/sandbox';
	const LEGACY_SANDBOX_BLOCK = 'pressmind/sandbox';

	/**
	 * AI provider.
	 *
	 * @var Gutenwright_AI_Provider
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param Gutenwright_AI_Provider $provider AI provider.
	 */
	public function __construct( Gutenwright_AI_Provider $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		foreach ( array( self::NAMESPACE, self::LEGACY_NAMESPACE ) as $namespace ) {
			register_rest_route(
				$namespace,
				self::ROUTE,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'prompt'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'postId'  => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'context' => array(
							'required' => false,
							'type'     => 'object',
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				self::STREAM_ROUTE,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'stream_generate' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'prompt'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'postId'  => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'context' => array(
							'required' => false,
							'type'     => 'object',
						),
					),
				)
			);
		}
	}

	/**
	 * Check request permissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'postId' ) );

		if ( $post_id > 0 ) {
			return current_user_can( 'edit_post', $post_id );
		}

		return current_user_can( 'edit_posts' );
	}

	/**
	 * Generate blocks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$prompt  = trim( (string) $request->get_param( 'prompt' ) );
		$post_id = absint( $request->get_param( 'postId' ) );
		$context = $request->get_param( 'context' );
		$context = is_array( $context ) ? $context : array();

		if ( '' === $prompt ) {
			return new WP_Error(
				'gutenwright_empty_prompt',
				__( 'Prompt cannot be empty.', 'gutenwright' ),
				array( 'status' => 400 )
			);
		}

		$context = $this->hydrate_context( $context, $post_id );
		$result  = $this->provider->generate( $prompt, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = $this->validate_sandbox_policy( $result );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = $this->import_assets( $result );
		$result = $this->sanitize_generation_result( $result );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Stream AI tokens to the editor, then send sanitized final blocks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public function stream_generate( WP_REST_Request $request ) {
		$prompt  = trim( (string) $request->get_param( 'prompt' ) );
		$post_id = absint( $request->get_param( 'postId' ) );
		$context = $request->get_param( 'context' );
		$context = is_array( $context ) ? $context : array();

		if ( '' === $prompt ) {
			return new WP_Error(
				'gutenwright_empty_prompt',
				__( 'Prompt cannot be empty.', 'gutenwright' ),
				array( 'status' => 400 )
			);
		}

		$this->start_event_stream();
		$this->send_event_stream_message(
			'start',
			array(
				'message' => __( 'Connected. Waiting for AI tokens...', 'gutenwright' ),
			)
		);
		$context = $this->hydrate_context( $context, $post_id );
		$result  = $this->provider->stream_generate(
			$prompt,
			$context,
			function ( $token ) {
				$this->send_event_stream_message(
					'token',
					array(
						'token' => $token,
					)
				);
			}
		);

		if ( is_wp_error( $result ) ) {
			$this->send_event_stream_message(
				'error',
				array(
					'message' => $result->get_error_message(),
				)
			);
			$this->send_event_stream_message( 'done', array() );
			exit;
		}

		$result = $this->validate_sandbox_policy( $result );

		if ( is_wp_error( $result ) ) {
			$this->send_event_stream_message(
				'error',
				array(
					'message' => $result->get_error_message(),
				)
			);
			$this->send_event_stream_message( 'done', array() );
			exit;
		}

		$result = $this->import_assets( $result );
		$result = $this->sanitize_generation_result( $result );

		if ( is_wp_error( $result ) ) {
			$this->send_event_stream_message(
				'error',
				array(
					'message' => $result->get_error_message(),
				)
			);
			$this->send_event_stream_message( 'done', array() );
			exit;
		}

		$this->send_event_stream_message( 'final', $result );
		$this->send_event_stream_message( 'done', array() );
		exit;
	}

	/**
	 * Start a server-sent events response.
	 */
	private function start_event_stream() {
		if ( function_exists( 'apache_setenv' ) ) {
			apache_setenv( 'no-gzip', '1' );
		}

		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', 'off' );
		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		if ( function_exists( 'ob_implicit_flush' ) ) {
			ob_implicit_flush( true );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		echo ':' . str_repeat( ' ', 2048 ) . "\n\n";
		flush();
	}

	/**
	 * Send a server-sent events message.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 */
	private function send_event_stream_message( $event, array $data ) {
		echo 'event: ' . sanitize_key( $event ) . "\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";

		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}

		flush();
	}

	/**
	 * Add trusted server-side post data to client context.
	 *
	 * @param array $context Client context.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	private function hydrate_context( array $context, $post_id ) {
		if ( $post_id <= 0 ) {
			return $context;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $context;
		}

		$context['serverPost'] = array(
			'id'      => $post->ID,
			'type'    => $post->post_type,
			'title'   => get_the_title( $post ),
			'excerpt' => $post->post_excerpt,
			'status'  => $post->post_status,
		);

		return $context;
	}

	/**
	 * Import generated assets into the Media Library when possible.
	 *
	 * @param array $result Generation result.
	 * @return array
	 */
	private function import_assets( array $result ) {
		if ( empty( $result['assets'] ) || ! is_array( $result['assets'] ) ) {
			return $result;
		}

		$imported_assets    = array();
		$failed_placeholders = array();
		$blocks             = isset( $result['serializedBlocks'] ) ? (string) $result['serializedBlocks'] : '';

		foreach ( $result['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$imported = $this->import_asset( $asset );

			if ( is_wp_error( $imported ) ) {
				$result['warnings'][] = $imported->get_error_message();

				if ( ! empty( $asset['placeholder'] ) ) {
					$failed_placeholders[] = (string) $asset['placeholder'];
				}

				continue;
			}

			if ( ! empty( $asset['placeholder'] ) && ! empty( $imported['url'] ) ) {
				$blocks = str_replace( $asset['placeholder'], $imported['url'], $blocks );
				$imported['placeholder'] = $asset['placeholder'];
			}

			$imported_assets[] = $imported;
		}

		if ( ! empty( $failed_placeholders ) ) {
			$blocks = $this->strip_blocks_with_placeholders( $blocks, $failed_placeholders );
		}

		$result['serializedBlocks'] = $this->add_imported_image_ids_to_blocks( $blocks, $imported_assets );
		$result['assets']           = $imported_assets;

		return $result;
	}

	/**
	 * Remove any top-level or nested block that still references a failed asset placeholder.
	 *
	 * @param string $serialized_blocks Serialized blocks.
	 * @param array  $placeholders      Placeholder strings whose asset generation failed.
	 * @return string
	 */
	private function strip_blocks_with_placeholders( $serialized_blocks, array $placeholders ) {
		if ( '' === trim( (string) $serialized_blocks ) || empty( $placeholders ) ) {
			return $serialized_blocks;
		}

		$blocks = parse_blocks( $serialized_blocks );
		$blocks = $this->filter_blocks_by_placeholders( $blocks, $placeholders );

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively drop blocks whose serialized form contains any failed placeholder.
	 *
	 * @param array $blocks       Parsed blocks.
	 * @param array $placeholders Placeholder strings.
	 * @return array
	 */
	private function filter_blocks_by_placeholders( array $blocks, array $placeholders ) {
		$kept = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->filter_blocks_by_placeholders( $block['innerBlocks'], $placeholders );
			}

			$serialized = serialize_block( $block );
			$contains   = false;

			foreach ( $placeholders as $placeholder ) {
				if ( '' !== $placeholder && false !== strpos( $serialized, $placeholder ) ) {
					$contains = true;
					break;
				}
			}

			if ( $contains ) {
				continue;
			}

			$kept[] = $block;
		}

		return $kept;
	}

	/**
	 * Add Media Library attachment IDs to generated image blocks.
	 *
	 * @param string $serialized_blocks Serialized blocks.
	 * @param array  $imported_assets   Imported assets.
	 * @return string
	 */
	private function add_imported_image_ids_to_blocks( $serialized_blocks, array $imported_assets ) {
		$image_assets = array_values(
			array_filter(
				$imported_assets,
				function ( $asset ) {
					return is_array( $asset ) && 'image' === ( $asset['type'] ?? '' ) && ! empty( $asset['id'] ) && ! empty( $asset['url'] );
				}
			)
		);

		if ( empty( $image_assets ) ) {
			return $serialized_blocks;
		}

		$blocks = parse_blocks( $serialized_blocks );
		$blocks = $this->add_imported_image_ids_to_parsed_blocks( $blocks, $image_assets );

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively add imported image attachment IDs to parsed blocks.
	 *
	 * @param array $blocks       Parsed blocks.
	 * @param array $image_assets Imported image assets.
	 * @return array
	 */
	private function add_imported_image_ids_to_parsed_blocks( array $blocks, array $image_assets ) {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->add_imported_image_ids_to_parsed_blocks( $block['innerBlocks'], $image_assets );
			}

			if ( 'core/image' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$block_html = $this->get_block_html_source( $block );
			$block_url  = isset( $block['attrs']['url'] ) ? (string) $block['attrs']['url'] : '';

			foreach ( $image_assets as $asset ) {
				if ( $block_url !== $asset['url'] && false === strpos( $block_html, $asset['url'] ) ) {
					continue;
				}

				$block['attrs']['id']  = absint( $asset['id'] );
				$block['attrs']['url'] = esc_url_raw( $asset['url'] );

				if ( isset( $block['innerHTML'] ) ) {
					$block['innerHTML'] = $this->add_wp_image_class_to_html( $block['innerHTML'], $asset['id'] );
				}

				if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
					$block['innerContent'] = array_map(
						function ( $content ) use ( $asset ) {
							return is_string( $content ) ? $this->add_wp_image_class_to_html( $content, $asset['id'] ) : $content;
						},
						$block['innerContent']
					);
				}

				break;
			}
		}

		return $blocks;
	}

	/**
	 * Ensure image markup contains the WordPress attachment class.
	 *
	 * @param string $html          HTML.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	private function add_wp_image_class_to_html( $html, $attachment_id ) {
		$class = 'wp-image-' . absint( $attachment_id );

		if ( false !== strpos( $html, $class ) ) {
			return $html;
		}

		if ( preg_match( '/<img\b[^>]*class=["\'][^"\']*["\']/i', $html ) ) {
			return preg_replace( '/(<img\b[^>]*class=["\'])([^"\']*)(["\'])/i', '$1$2 ' . $class . '$3', $html, 1 );
		}

		return preg_replace( '/<img\b/i', '<img class="' . esc_attr( $class ) . '"', $html, 1 );
	}

	/**
	 * Import a single asset.
	 *
	 * @param array $asset Asset data.
	 * @return array|WP_Error
	 */
	private function import_asset( array $asset ) {
		$type     = isset( $asset['type'] ) ? sanitize_key( $asset['type'] ) : 'asset';
		$url      = isset( $asset['url'] ) ? esc_url_raw( $asset['url'] ) : '';
		$prompt   = isset( $asset['prompt'] ) ? sanitize_textarea_field( $asset['prompt'] ) : '';
		$filename = isset( $asset['filename'] ) ? sanitize_file_name( $asset['filename'] ) : '';

		if ( empty( $filename ) ) {
			$filename = 'image' === $type ? 'gutenwright-generated-image.png' : 'gutenwright-generated-asset';
		}

		if ( ! $url && 'image' === $type && $prompt ) {
			return $this->import_generated_image_asset( $prompt, $filename );
		}

		if ( ! $url ) {
			return new WP_Error(
				'gutenwright_asset_missing_url',
				__( 'Generated asset did not include an importable URL.', 'gutenwright' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( $url, 90 );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			return $attachment_id;
		}

		return array(
			'id'   => $attachment_id,
			'url'  => wp_get_attachment_url( $attachment_id ),
			'type' => $type,
		);
	}

	/**
	 * Generate an image and import it into the Media Library.
	 *
	 * @param string $prompt   Image prompt.
	 * @param string $filename Desired filename.
	 * @return array|WP_Error
	 */
	private function import_generated_image_asset( $prompt, $filename ) {
		if ( ! $this->provider->is_image_generation_enabled() ) {
			return new WP_Error(
				'gutenwright_image_generation_disabled',
				__( 'Image generation is disabled in plugin settings.', 'gutenwright' )
			);
		}

		$image = $this->provider->generate_image( $prompt );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( ! empty( $image['url'] ) ) {
			return $this->import_remote_image_asset( $image['url'], $filename );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$extension = isset( $image['extension'] ) ? sanitize_key( $image['extension'] ) : 'png';
		$filename  = preg_replace( '/\.[a-z0-9]+$/i', '', $filename ) . '.' . $extension;
		$temp_file = wp_tempnam( $filename );

		if ( ! $temp_file ) {
			return new WP_Error(
				'gutenwright_image_temp_file_failed',
				__( 'Could not create a temporary file for the generated image.', 'gutenwright' )
			);
		}

		if ( false === file_put_contents( $temp_file, $image['bytes'] ) ) {
			@unlink( $temp_file );

			return new WP_Error(
				'gutenwright_image_write_failed',
				__( 'Could not write the generated image file.', 'gutenwright' )
			);
		}

		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $temp_file,
			'type'     => isset( $image['mime_type'] ) ? $image['mime_type'] : 'image/png',
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			return $attachment_id;
		}

		return array(
			'id'   => $attachment_id,
			'url'  => wp_get_attachment_url( $attachment_id ),
			'type' => 'image',
		);
	}

	/**
	 * Import a generated remote image URL into the Media Library.
	 *
	 * @param string $url      Remote image URL.
	 * @param string $filename Desired filename.
	 * @return array|WP_Error
	 */
	private function import_remote_image_asset( $url, $filename ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( esc_url_raw( $url ), 90 );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			return $attachment_id;
		}

		return array(
			'id'   => $attachment_id,
			'url'  => wp_get_attachment_url( $attachment_id ),
			'type' => 'image',
		);
	}

	/**
	 * Sanitize and validate a generation result.
	 *
	 * @param array $result Generation result.
	 * @return array|WP_Error
	 */
	private function sanitize_generation_result( array $result ) {
		$serialized_blocks = isset( $result['serializedBlocks'] ) ? trim( (string) $result['serializedBlocks'] ) : '';
		$warnings          = isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? array_values( array_filter( array_map( 'strval', $result['warnings'] ) ) ) : array();

		if ( '' === $serialized_blocks ) {
			if ( ! empty( $warnings ) ) {
				return new WP_Error(
					'gutenwright_asset_generation_failed',
					sprintf(
						/* translators: %s: comma-separated provider warning messages. */
						__( 'Generation failed: %s', 'gutenwright' ),
						implode( '; ', $warnings )
					),
					array( 'status' => 502 )
				);
			}

			$summary = isset( $result['summary'] ) ? trim( (string) $result['summary'] ) : '';
			$detail  = $summary
				? sprintf(
					/* translators: %s: model-provided summary. */
					__( 'AI provider returned a description but no block markup. Model said: "%s". Try a more specific block prompt (e.g. "as a paragraph", "as a core/image block").', 'gutenwright' ),
					$summary
				)
				: __( 'AI provider did not return any block markup.', 'gutenwright' );

			return new WP_Error(
				'gutenwright_empty_blocks',
				$detail,
				array( 'status' => 502 )
			);
		}

		$blocks = parse_blocks( $serialized_blocks );
		$blocks = $this->sanitize_blocks( $blocks );

		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		if ( empty( $blocks ) ) {
			return new WP_Error(
				'gutenwright_invalid_blocks',
				__( 'AI provider returned unsupported or invalid block markup.', 'gutenwright' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'summary'          => isset( $result['summary'] ) ? sanitize_text_field( $result['summary'] ) : '',
			'serializedBlocks' => serialize_blocks( $blocks ),
			'assets'           => isset( $result['assets'] ) && is_array( $result['assets'] ) ? $result['assets'] : array(),
			'warnings'         => isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? array_values( array_map( 'sanitize_text_field', $result['warnings'] ) ) : array(),
		);
	}

	/**
	 * Sanitize parsed blocks and remove unsupported blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array|WP_Error
	 */
	private function sanitize_blocks( array $blocks ) {
		$sanitized = array();

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) && empty( trim( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}

			if ( ! empty( $block['blockName'] ) && ! in_array( $block['blockName'], $this->get_allowed_blocks(), true ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->sanitize_blocks( $block['innerBlocks'] );

				if ( is_wp_error( $block['innerBlocks'] ) ) {
					return $block['innerBlocks'];
				}
			}

			if ( isset( $block['blockName'] ) && $this->is_sandbox_block_name( $block['blockName'] ) ) {
				if ( $this->is_sandbox_generation_disallowed() ) {
					return $this->get_sandbox_disallowed_error();
				}

				$block['blockName']    = self::SANDBOX_BLOCK;
				$block['attrs']        = $this->sanitize_sandbox_attrs(
					isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array()
				);
				$block['innerHTML']    = '';
				$block['innerContent'] = array();
				$sanitized[]           = $block;
				continue;
			}

			if ( isset( $block['blockName'] ) && 'core/html' === $block['blockName'] && $this->should_convert_html_to_sandbox( $block ) ) {
				if ( $this->is_sandbox_generation_disallowed() ) {
					return $this->get_sandbox_disallowed_error();
				}

				$sanitized[] = $this->convert_html_block_to_sandbox( $block );
				continue;
			}

			$block['attrs']       = $this->sanitize_block_attrs(
				isset( $block['blockName'] ) ? $block['blockName'] : '',
				isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array()
			);
			$block['innerHTML']   = isset( $block['innerHTML'] ) ? $this->sanitize_html( $block['innerHTML'] ) : '';
			$block['innerContent'] = isset( $block['innerContent'] ) && is_array( $block['innerContent'] )
				? array_map( array( $this, 'sanitize_inner_content' ), $block['innerContent'] )
				: array();

			$sanitized[] = $block;
		}

		return $sanitized;
	}

	/**
	 * Reject generated markup that would require sandboxed iframe rendering.
	 *
	 * @param array $result Generation result.
	 * @return array|WP_Error
	 */
	private function validate_sandbox_policy( array $result ) {
		if ( ! $this->is_sandbox_generation_disallowed() ) {
			return $result;
		}

		$serialized_blocks = isset( $result['serializedBlocks'] ) ? trim( (string) $result['serializedBlocks'] ) : '';

		if ( '' === $serialized_blocks ) {
			return $result;
		}

		if ( $this->blocks_require_sandbox( parse_blocks( $serialized_blocks ) ) ) {
			return $this->get_sandbox_disallowed_error();
		}

		return $result;
	}

	/**
	 * Determine whether parsed blocks would require sandbox rendering.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return bool
	 */
	private function blocks_require_sandbox( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && $this->is_sandbox_block_name( $block['blockName'] ) ) {
				return true;
			}

			if ( isset( $block['blockName'] ) && 'core/html' === $block['blockName'] && $this->should_convert_html_to_sandbox( $block ) ) {
				return true;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && $this->blocks_require_sandbox( $block['innerBlocks'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check current and legacy sandbox block names.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private function is_sandbox_block_name( $block_name ) {
		return in_array( $block_name, array( self::SANDBOX_BLOCK, self::LEGACY_SANDBOX_BLOCK ), true );
	}

	/**
	 * Build the shared sandbox policy error.
	 *
	 * @return WP_Error
	 */
	private function get_sandbox_disallowed_error() {
		return new WP_Error(
			'gutenwright_sandbox_disallowed',
			__( 'Sandboxed AI HTML is disabled because DISALLOW_UNFILTERED_HTML is enabled for this site. Ask for static blocks, HTML, or SVG without scripts or style tags instead.', 'gutenwright' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Determine whether site policy disallows generated sandbox blocks.
	 *
	 * @return bool
	 */
	private function is_sandbox_generation_disallowed() {
		if ( function_exists( 'gutenwright_is_sandbox_generation_disallowed' ) ) {
			return gutenwright_is_sandbox_generation_disallowed();
		}

		if ( ! defined( 'DISALLOW_UNFILTERED_HTML' ) ) {
			return false;
		}

		$value = constant( 'DISALLOW_UNFILTERED_HTML' );

		return true === $value || 1 === $value || '1' === (string) $value || 'true' === strtolower( (string) $value );
	}

	/**
	 * Determine whether a Custom HTML block needs iframe isolation.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private function should_convert_html_to_sandbox( array $block ) {
		$html = $this->get_block_html_source( $block );

		return (bool) preg_match( '/<\s*(script|style)\b|\son[a-z]+\s*=/i', $html );
	}

	/**
	 * Convert interactive Custom HTML into the sandbox block.
	 *
	 * @param array $block Parsed core/html block.
	 * @return array
	 */
	private function convert_html_block_to_sandbox( array $block ) {
		$html = $this->get_block_html_source( $block );
		$css  = '';
		$js   = '';

		if ( preg_match_all( '/<\s*style\b[^>]*>(.*?)<\s*\/\s*style\s*>/is', $html, $matches ) ) {
			$css = trim( implode( "\n\n", $matches[1] ) );
		}

		if ( preg_match_all( '/<\s*script\b[^>]*>(.*?)<\s*\/\s*script\s*>/is', $html, $matches ) ) {
			$js = trim( implode( "\n\n", $matches[1] ) );
		}

		$html = preg_replace( '/<\s*style\b[^>]*>.*?<\s*\/\s*style\s*>/is', '', $html );
		$html = preg_replace( '/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $html );

		return array(
			'blockName'    => self::SANDBOX_BLOCK,
			'attrs'        => $this->sanitize_sandbox_attrs(
				array(
					'title'  => __( 'AI generated interactive content', 'gutenwright' ),
					'height' => 640,
					'html'   => $html,
					'css'    => $css,
					'js'     => $js,
				)
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * Get the original HTML-like source for a parsed block.
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	private function get_block_html_source( array $block ) {
		if ( ! empty( $block['innerHTML'] ) ) {
			return (string) $block['innerHTML'];
		}

		if ( empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return '';
		}

		return implode(
			'',
			array_filter(
				$block['innerContent'],
				function ( $content ) {
					return is_string( $content );
				}
			)
		);
	}

	/**
	 * Sanitize innerContent strings while preserving null child markers.
	 *
	 * @param mixed $content Inner content item.
	 * @return mixed
	 */
	public function sanitize_inner_content( $content ) {
		if ( null === $content ) {
			return null;
		}

		return is_string( $content ) ? $this->sanitize_html( $content ) : '';
	}

	/**
	 * Sanitize block attributes conservatively before re-serializing comments.
	 *
	 * @param string $block_name Block name.
	 * @param array  $attrs      Block attributes.
	 * @return array
	 */
	private function sanitize_block_attrs( $block_name, array $attrs ) {
		$clean = array();

		foreach ( $attrs as $key => $value ) {
			$key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( in_array( $key, array( 'url', 'href' ), true ) ) {
				$clean[ $key ] = esc_url_raw( (string) $value );
				continue;
			}

			if ( in_array( $key, array( 'id', 'width', 'height' ), true ) ) {
				$clean[ $key ] = absint( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$clean[ $key ] = sanitize_text_field( $value );
				continue;
			}

			if ( is_array( $value ) && in_array( $block_name, array( 'core/group', 'core/columns', 'core/column' ), true ) ) {
				$clean[ $key ] = $this->sanitize_nested_attr_array( $value );
			}
		}

		if ( 'core/html' === $block_name ) {
			$clean['preview'] = true;
		}

		return $clean;
	}

	/**
	 * Sanitize nested layout-like block attributes.
	 *
	 * @param array $value Attribute array.
	 * @return array
	 */
	private function sanitize_nested_attr_array( array $value ) {
		$clean = array();

		foreach ( $value as $key => $item ) {
			$key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$clean[ $key ] = $item;
				continue;
			}

			if ( is_string( $item ) ) {
				$clean[ $key ] = sanitize_text_field( $item );
			}
		}

		return $clean;
	}

	/**
	 * Allowed block names for AI output.
	 *
	 * @return array
	 */
	private function get_allowed_blocks() {
		return array(
			'core/paragraph',
			'core/heading',
			'core/group',
			'core/columns',
			'core/column',
			'core/html',
			'core/table',
			'core/image',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/details',
			'core/buttons',
			'core/button',
			'core/separator',
			'core/spacer',
			'core/embed',
			self::SANDBOX_BLOCK,
			self::LEGACY_SANDBOX_BLOCK,
		);
	}

	/**
	 * Sanitize sandbox block attributes while preserving self-contained code.
	 *
	 * The code is not injected into the parent page. It renders only inside an
	 * iframe without same-origin privileges, so scripts cannot reach WordPress.
	 *
	 * @param array $attrs Raw block attributes.
	 * @return array
	 */
	private function sanitize_sandbox_attrs( array $attrs ) {
		return array(
			'title'  => isset( $attrs['title'] ) ? sanitize_text_field( $attrs['title'] ) : __( 'AI generated interactive content', 'gutenwright' ),
			'height' => isset( $attrs['height'] ) ? max( 240, min( 1200, absint( $attrs['height'] ) ) ) : 640,
			'html'   => isset( $attrs['html'] ) ? $this->strip_sandbox_network_access( (string) $attrs['html'] ) : '',
			'css'    => isset( $attrs['css'] ) ? $this->strip_sandbox_network_access( (string) $attrs['css'] ) : '',
			'js'     => isset( $attrs['js'] ) ? $this->strip_sandbox_network_access( (string) $attrs['js'] ) : '',
		);
	}

	/**
	 * Remove obvious external access primitives from sandboxed snippets.
	 *
	 * @param string $code Snippet.
	 * @return string
	 */
	private function strip_sandbox_network_access( $code ) {
		$patterns = array(
			'/<\s*iframe\b[^>]*>.*?<\s*\/\s*iframe\s*>/is',
			'/<\s*(script|link)\b[^>]*(src|href)\s*=\s*["\'][^"\']+["\'][^>]*>.*?(?:<\s*\/\s*\1\s*>)?/is',
			'/\b(fetch|XMLHttpRequest|WebSocket|EventSource)\s*\(/i',
			'/\b(localStorage|sessionStorage|indexedDB|document\.cookie)\b/i',
			'/\b(window\.top|window\.parent|parent\.)\b/i',
			'/@import\s+url\s*\([^)]*\)\s*;?/i',
			'/url\s*\(\s*["\']?https?:\/\/[^)]*\)/i',
		);

		return preg_replace( $patterns, '', $code );
	}

	/**
	 * Sanitize generated HTML, including a conservative inline SVG allowlist.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function sanitize_html( $html ) {
		$allowed = wp_kses_allowed_html( 'post' );

		$allowed['svg'] = array(
			'aria-hidden'     => true,
			'aria-label'      => true,
			'class'           => true,
			'fill'            => true,
			'height'          => true,
			'role'            => true,
			'stroke'          => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'stroke-width'    => true,
			'viewbox'         => true,
			'viewBox'         => true,
			'width'           => true,
			'xmlns'           => true,
		);

		foreach ( array( 'circle', 'ellipse', 'g', 'line', 'path', 'polygon', 'polyline', 'rect', 'text', 'tspan' ) as $tag ) {
			$allowed[ $tag ] = array(
				'aria-label'      => true,
				'class'           => true,
				'cx'              => true,
				'cy'              => true,
				'd'               => true,
				'fill'            => true,
				'font-family'     => true,
				'font-size'       => true,
				'font-weight'     => true,
				'height'          => true,
				'points'          => true,
				'r'               => true,
				'rx'              => true,
				'ry'              => true,
				'stroke'          => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'stroke-width'    => true,
				'text-anchor'     => true,
				'transform'       => true,
				'width'           => true,
				'x'               => true,
				'x1'              => true,
				'x2'              => true,
				'y'               => true,
				'y1'              => true,
				'y2'              => true,
			);
		}

		$allowed['title'] = array();
		$allowed['desc']  = array();

		return wp_kses( $html, $allowed );
	}
}
