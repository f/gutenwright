<?php
/**
 * Plugin Name: Gutenwright
 * Description: Generate Gutenberg-compatible blocks from prompts and current post context.
 * Version: 0.0.8
 * Author: Gutenwright
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gutenwright
 *
 * @package Gutenwright
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GUTENWRIGHT_VERSION', '0.0.8' );
define( 'GUTENWRIGHT_PLUGIN_FILE', __FILE__ );
define( 'GUTENWRIGHT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GUTENWRIGHT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GUTENWRIGHT_PLUGIN_DIR . 'includes/class-settings.php';
require_once GUTENWRIGHT_PLUGIN_DIR . 'includes/class-ai-provider.php';
require_once GUTENWRIGHT_PLUGIN_DIR . 'includes/class-rest-controller.php';

/**
 * Register the editor utility block.
 */
function gutenwright_register_block() {
	$block_path = GUTENWRIGHT_PLUGIN_DIR . 'build/ai-prompt-block';

	if ( file_exists( $block_path . '/block.json' ) ) {
		register_block_type( $block_path );
	}

	register_block_type(
		'pressmind/prompt-block',
		array(
			'api_version'     => 3,
			'title'           => __( 'Legacy Gutenwright Prompt', 'gutenwright' ),
			'category'        => 'widgets',
			'attributes'      => array(
				'prompt' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'render_callback' => '__return_empty_string',
			'supports'        => array(
				'html'     => false,
				'multiple' => true,
			),
		)
	);

	$sandbox_block_args = array(
		'api_version'     => 3,
		'title'           => __( 'AI Sandboxed Content', 'gutenwright' ),
		'category'        => 'widgets',
		'attributes'      => array(
			'title'  => array(
				'type'    => 'string',
				'default' => __( 'AI generated interactive content', 'gutenwright' ),
			),
			'html'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'css'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'js'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'height' => array(
				'type'    => 'number',
				'default' => 640,
			),
		),
		'render_callback' => 'gutenwright_render_sandbox_block',
		'supports'        => array(
			'html' => false,
		),
	);

	register_block_type( 'gutenwright/sandbox', $sandbox_block_args );
	register_block_type( 'pressmind/sandbox', $sandbox_block_args );
}
add_action( 'init', 'gutenwright_register_block' );

/**
 * Determine whether sandboxed generated HTML is disabled by site policy.
 *
 * @return bool
 */
function gutenwright_is_sandbox_generation_disallowed() {
	if ( ! defined( 'DISALLOW_UNFILTERED_HTML' ) ) {
		return false;
	}

	$value = constant( 'DISALLOW_UNFILTERED_HTML' );

	return true === $value || 1 === $value || '1' === (string) $value || 'true' === strtolower( (string) $value );
}

/**
 * Determine whether generated code should be injected directly.
 *
 * @return bool
 */
function gutenwright_is_seamless_mode_enabled() {
	if ( gutenwright_is_sandbox_generation_disallowed() ) {
		return false;
	}

	$options = get_option( Gutenwright_Settings::OPTION_NAME, array() );

	if ( empty( $options ) ) {
		$options = get_option( Gutenwright_Settings::LEGACY_OPTION_NAME, array() );
	}

	$options = wp_parse_args(
		is_array( $options ) ? $options : array(),
		array(
			'seamless_mode' => 0,
		)
	);

	return ! empty( $options['seamless_mode'] );
}

/**
 * Expose editor policy flags to the prompt block script.
 */
function gutenwright_enqueue_editor_settings() {
	wp_add_inline_script(
		'gutenwright-prompt-block-editor-script',
		'window.gutenwrightPromptBlock = ' . wp_json_encode(
			array(
				'disallowSandboxGeneration' => gutenwright_is_sandbox_generation_disallowed(),
				'seamlessMode'              => gutenwright_is_seamless_mode_enabled(),
			)
		) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'gutenwright_enqueue_editor_settings' );

/**
 * Render generated interactive content inside an isolated iframe.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function gutenwright_render_sandbox_block( $attributes ) {
	$title  = isset( $attributes['title'] ) ? sanitize_text_field( $attributes['title'] ) : __( 'AI generated interactive content', 'gutenwright' );
	$html   = isset( $attributes['html'] ) ? (string) $attributes['html'] : '';
	$css    = isset( $attributes['css'] ) ? (string) $attributes['css'] : '';
	$js     = isset( $attributes['js'] ) ? (string) $attributes['js'] : '';
	$height = isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 640;
	$height = max( 240, $height );

	if ( gutenwright_is_sandbox_generation_disallowed() ) {
		return sprintf(
			'<div class="wp-block-gutenwright-sandbox" style="border:1px solid #ddd;border-radius:4px;padding:16px;background:#fff;"><strong>%1$s</strong><p>%2$s</p></div>',
			esc_html( $title ),
			esc_html__( 'Sandboxed AI HTML is disabled because DISALLOW_UNFILTERED_HTML is enabled for this site.', 'gutenwright' )
		);
	}

	if ( gutenwright_is_seamless_mode_enabled() ) {
		return gutenwright_render_seamless_block( $title, $html, $css, $js );
	}

	$id     = wp_unique_id( 'gutenwright-sandbox-' );
	$srcdoc = gutenwright_build_sandbox_srcdoc( $html, $css, $js, $id );

	return sprintf(
		'<div class="wp-block-gutenwright-sandbox"><iframe id="%1$s" title="%2$s" sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy" scrolling="no" style="display:block;width:100%%;height:%3$dpx;border:1px solid #ddd;border-radius:4px;background:#fff;overflow:hidden;" srcdoc="%4$s"></iframe><script>(function(){var iframe=document.getElementById("%5$s");if(!iframe){return;}window.addEventListener("message",function(event){if(event.source!==iframe.contentWindow){return;}var data=event.data||{};if(data.type!=="gutenwright:sandbox:resize"||data.id!=="%6$s"){return;}var height=Math.max(%7$d,Number(data.height)||0);iframe.style.height=height+"px";});}());</script></div>',
		esc_attr( $id ),
		esc_attr( $title ),
		$height,
		esc_attr( $srcdoc ),
		esc_js( $id ),
		esc_js( $id ),
		$height
	);
}

/**
 * Render generated content directly in the page for seamless mode.
 *
 * @param string $title Accessible block title.
 * @param string $html  Body HTML.
 * @param string $css   Page CSS.
 * @param string $js    Page JavaScript.
 * @return string
 */
function gutenwright_render_seamless_block( $title, $html, $css, $js ) {
	$css = str_ireplace( '</style', '<\/style', $css );
	$js  = str_ireplace( '</script', '<\/script', $js );

	return sprintf(
		'<div class="wp-block-gutenwright-sandbox gutenwright-seamless-block" data-gutenwright-mode="seamless" aria-label="%1$s"><style>%2$s</style>%3$s<script>(function(){try{%4$s}catch(error){if(window.console){console.error("Gutenwright seamless block failed",error);}}}());</script></div>',
		esc_attr( $title ),
		$css,
		$html,
		$js
	);
}

/**
 * Build iframe document for sandboxed generated HTML/CSS/JS.
 *
 * @param string $html Body HTML.
 * @param string $css  Scoped CSS.
 * @param string $js   Inline JS.
 * @param string $id   Sandbox iframe id.
 * @return string
 */
function gutenwright_build_sandbox_srcdoc( $html, $css, $js, $id ) {
	$js = str_ireplace( '</script', '<\/script', $js );
	$resize_script = sprintf(
		'(function(){var sandboxId=%s;var lastHeight=0;function measure(){var body=document.body;var html=document.documentElement;var height=Math.ceil(Math.max(body?body.scrollHeight:0,body?body.offsetHeight:0,html?html.scrollHeight:0,html?html.offsetHeight:0));if(height&&Math.abs(height-lastHeight)>1){lastHeight=height;parent.postMessage({type:"gutenwright:sandbox:resize",id:sandboxId,height:height},"*");}}window.addEventListener("load",measure);window.addEventListener("resize",measure);if(window.ResizeObserver){new ResizeObserver(measure).observe(document.documentElement);if(document.body){new ResizeObserver(measure).observe(document.body);}}if(window.MutationObserver){new MutationObserver(measure).observe(document.documentElement,{childList:true,subtree:true,attributes:true,characterData:true});}document.addEventListener("load",function(event){if(event.target&&event.target.tagName==="IMG"){measure();}},true);setTimeout(measure,0);setTimeout(measure,100);setTimeout(measure,500);}());',
		wp_json_encode( $id )
	);
	$resize_script = str_ireplace( '</script', '<\/script', $resize_script );

	return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;padding:0;box-sizing:border-box;overflow:hidden;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}*,*:before,*:after{box-sizing:inherit;}' . $css . '</style></head><body>' . $html . '<script>' . $resize_script . '</script><script>' . $js . '</script></body></html>';
}

/**
 * Bootstrap plugin services.
 */
function gutenwright_bootstrap() {
	$settings = new Gutenwright_Settings();
	$settings->register();

	$provider = new Gutenwright_AI_Provider( $settings );
	$rest     = new Gutenwright_REST_Controller( $provider );
	$rest->register();
}
add_action( 'plugins_loaded', 'gutenwright_bootstrap' );
