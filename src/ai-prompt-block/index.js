import {
	createBlock,
	parse,
	registerBlockType,
	serialize,
} from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import Edit from './edit';
import metadata from './block.json';
import './editor.scss';
import save from './save';

const PROMPT_BLOCK_NAME = metadata.name;
const LEGACY_PROMPT_BLOCK_NAME = 'pressmind/prompt-block';
const SANDBOX_BLOCK_NAME = 'gutenwright/sandbox';
const LEGACY_SANDBOX_BLOCK_NAME = 'pressmind/sandbox';
const SANDBOX_BLOCK_NAMES = [ SANDBOX_BLOCK_NAME, LEGACY_SANDBOX_BLOCK_NAME ];
const gutenwrightPromptBlockSettings = window.gutenwrightPromptBlock || {};
const isSandboxGenerationDisallowed = Boolean(
	gutenwrightPromptBlockSettings.disallowSandboxGeneration
);
const isSeamlessModeEnabled = Boolean(
	gutenwrightPromptBlockSettings.seamlessMode
);
const sandboxDisallowedMessage = __(
	'Sandboxed AI HTML is disabled because DISALLOW_UNFILTERED_HTML is enabled for this site.',
	'gutenwright'
);

const stripHtml = ( value = '' ) => {
	const element = document.createElement( 'div' );
	element.innerHTML = value;

	return ( element.textContent || element.innerText || '' ).trim();
};

const textFromAttributes = ( attributes = {} ) =>
	stripHtml(
		attributes.content ||
			attributes.value ||
			attributes.values ||
			attributes.citation ||
			''
	);

const createPromptBlockFromText = ( attributes ) =>
	createBlock( PROMPT_BLOCK_NAME, {
		prompt: textFromAttributes( attributes ),
	} );

const promptBlockSettings = {
	edit: Edit,
	save,
	transforms: {
		from: [
			{
				type: 'block',
				blocks: [
					'core/paragraph',
					'core/heading',
					'core/quote',
					'core/list',
					'core/preformatted',
					'core/code',
					'core/verse',
				],
				transform: createPromptBlockFromText,
			},
		],
	},
};

registerBlockType( PROMPT_BLOCK_NAME, promptBlockSettings );
registerBlockType( LEGACY_PROMPT_BLOCK_NAME, {
	...promptBlockSettings,
	title: __( 'Legacy Gutenwright Prompt', 'gutenwright' ),
} );

const buildSandboxSrcDoc = ( { html = '', css = '', js = '' }, sandboxId ) => {
	const safeJs = js.replace( /<\/script/gi, '<\\/script' );
	const resizeScript = `
		(function () {
			var sandboxId = ${ JSON.stringify( sandboxId ) };
			var lastHeight = 0;
			function measure() {
				var body = document.body;
				var html = document.documentElement;
				var height = Math.ceil(Math.max(
					body ? body.scrollHeight : 0,
					body ? body.offsetHeight : 0,
					html ? html.scrollHeight : 0,
					html ? html.offsetHeight : 0
				));
				if (height && Math.abs(height - lastHeight) > 1) {
					lastHeight = height;
					parent.postMessage({ type: 'gutenwright:sandbox:resize', id: sandboxId, height: height }, '*');
				}
			}
			window.addEventListener('load', measure);
			window.addEventListener('resize', measure);
			if (window.ResizeObserver) {
				new ResizeObserver(measure).observe(document.documentElement);
				if (document.body) {
					new ResizeObserver(measure).observe(document.body);
				}
			}
			if (window.MutationObserver) {
				new MutationObserver(measure).observe(document.documentElement, {
					childList: true,
					subtree: true,
					attributes: true,
					characterData: true
				});
			}
			document.addEventListener('load', function (event) {
				if (event.target && event.target.tagName === 'IMG') {
					measure();
				}
			}, true);
			setTimeout(measure, 0);
			setTimeout(measure, 100);
			setTimeout(measure, 500);
		})();`.replace( /<\/script/gi, '<\\/script' );

	return `<!doctype html>
<html>
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width,initial-scale=1" />
		<style>
			html,body{margin:0;padding:0;box-sizing:border-box;overflow:hidden;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
			*,*:before,*:after{box-sizing:inherit;}
			${ css }
		</style>
	</head>
	<body>
		${ html }
		<script>${ resizeScript }</script>
		<script>${ safeJs }</script>
	</body>
</html>`;
};

function SeamlessPreview( { attributes } ) {
	const previewRef = useRef();

	useEffect( () => {
		const node = previewRef.current;

		if ( ! node ) {
			return;
		}

		node.innerHTML = '';

		if ( attributes.css ) {
			const style = document.createElement( 'style' );
			style.textContent = attributes.css;
			node.appendChild( style );
		}

		const content = document.createElement( 'div' );
		content.className = 'gutenwright-seamless-block__html';
		content.innerHTML = attributes.html || '';
		node.appendChild( content );

		if ( attributes.js ) {
			try {
				window.Function( attributes.js )();
			} catch ( error ) {
				// eslint-disable-next-line no-console
				console.error( 'Gutenwright seamless block failed', error );
			}
		}
	}, [ attributes.html, attributes.css, attributes.js ] );

	return (
		<div
			ref={ previewRef }
			className="gutenwright-seamless-block"
			data-gutenwright-mode="seamless"
			aria-label={ attributes.title }
		/>
	);
}

function SandboxEdit( { attributes, setAttributes, isSelected, clientId } ) {
	const blockProps = useBlockProps( {
		className: 'gutenwright-sandbox-block',
	} );
	const iframeRef = useRef();
	const sandboxId = `gutenwright-sandbox-${ clientId }`;
	const minHeight = Math.max( 240, Number( attributes.height ) || 640 );
	const [ iframeHeight, setIframeHeight ] = useState( minHeight );

	useEffect( () => {
		setIframeHeight( minHeight );
	}, [ minHeight, attributes.html, attributes.css, attributes.js ] );

	useEffect( () => {
		const handleMessage = ( event ) => {
			if ( event.source !== iframeRef.current?.contentWindow ) {
				return;
			}

			const data = event.data || {};

			if (
				data.type !== 'gutenwright:sandbox:resize' ||
				data.id !== sandboxId
			) {
				return;
			}

			const nextHeight = Math.max(
				minHeight,
				Number( data.height ) || 0
			);

			setIframeHeight( nextHeight );
		};

		window.addEventListener( 'message', handleMessage );

		return () => window.removeEventListener( 'message', handleMessage );
	}, [ minHeight, sandboxId ] );

	if ( isSandboxGenerationDisallowed ) {
		return (
			<div { ...blockProps }>
				{ isSelected ? (
					<div className="gutenwright-sandbox-block__editor">
						<TextControl
							label={ __( 'Title', 'gutenwright' ) }
							value={ attributes.title }
							onChange={ ( title ) => setAttributes( { title } ) }
						/>
						<TextControl
							label={ __( 'Height', 'gutenwright' ) }
							type="number"
							value={ attributes.height }
							onChange={ ( nextHeight ) =>
								setAttributes( {
									height: Number( nextHeight ) || 640,
								} )
							}
						/>
					</div>
				) : null }
				<Notice status="warning" isDismissible={ false }>
					{ sandboxDisallowedMessage }
				</Notice>
			</div>
		);
	}

	if ( isSeamlessModeEnabled ) {
		return (
			<div { ...blockProps }>
				{ isSelected ? (
					<div className="gutenwright-sandbox-block__editor">
						<TextControl
							label={ __( 'Title', 'gutenwright' ) }
							value={ attributes.title }
							onChange={ ( title ) => setAttributes( { title } ) }
						/>
						<TextControl
							label={ __( 'Height', 'gutenwright' ) }
							type="number"
							value={ attributes.height }
							onChange={ ( nextHeight ) =>
								setAttributes( {
									height: Number( nextHeight ) || 640,
								} )
							}
						/>
						<TextareaControl
							label={ __( 'HTML', 'gutenwright' ) }
							value={ attributes.html }
							rows={ 6 }
							onChange={ ( html ) => setAttributes( { html } ) }
						/>
						<TextareaControl
							label={ __( 'CSS', 'gutenwright' ) }
							value={ attributes.css }
							rows={ 6 }
							onChange={ ( css ) => setAttributes( { css } ) }
						/>
						<TextareaControl
							label={ __( 'JavaScript', 'gutenwright' ) }
							value={ attributes.js }
							rows={ 6 }
							onChange={ ( js ) => setAttributes( { js } ) }
						/>
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Seamless mode is enabled. This block injects generated HTML, CSS, and JavaScript directly into the editor preview and the published page.',
								'gutenwright'
							) }
						</Notice>
					</div>
				) : null }
				<SeamlessPreview attributes={ attributes } />
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			{ isSelected ? (
				<div className="gutenwright-sandbox-block__editor">
					<TextControl
						label={ __( 'Title', 'gutenwright' ) }
						value={ attributes.title }
						onChange={ ( title ) => setAttributes( { title } ) }
					/>
					<TextControl
						label={ __( 'Height', 'gutenwright' ) }
						type="number"
						value={ attributes.height }
						onChange={ ( nextHeight ) =>
							setAttributes( {
								height: Number( nextHeight ) || 640,
							} )
						}
					/>
					<TextareaControl
						label={ __( 'HTML', 'gutenwright' ) }
						value={ attributes.html }
						rows={ 6 }
						onChange={ ( html ) => setAttributes( { html } ) }
					/>
					<TextareaControl
						label={ __( 'CSS', 'gutenwright' ) }
						value={ attributes.css }
						rows={ 6 }
						onChange={ ( css ) => setAttributes( { css } ) }
					/>
					<TextareaControl
						label={ __( 'JavaScript', 'gutenwright' ) }
						value={ attributes.js }
						rows={ 6 }
						onChange={ ( js ) => setAttributes( { js } ) }
					/>
				</div>
			) : null }
			<iframe
				ref={ iframeRef }
				title={ attributes.title }
				sandbox="allow-scripts"
				referrerPolicy="no-referrer"
				scrolling="no"
				srcDoc={ buildSandboxSrcDoc( attributes, sandboxId ) }
				style={ {
					background: '#fff',
					border: '1px solid #ddd',
					borderRadius: '4px',
					display: 'block',
					height: iframeHeight,
					overflow: 'hidden',
					width: '100%',
				} }
			/>
		</div>
	);
}

const getRestUrl = ( path ) => {
	const root = window.wpApiSettings?.root || '/wp-json/';

	return `${ root.replace( /\/$/, '' ) }/${ path.replace( /^\//, '' ) }`;
};

const getRestNonce = () => window.wpApiSettings?.nonce || '';

const parseServerSentEvent = ( rawEvent ) => {
	const lines = rawEvent.split( /\r?\n/ );
	let event = 'message';
	const dataLines = [];

	lines.forEach( ( line ) => {
		if ( line.startsWith( 'event:' ) ) {
			event = line.slice( 6 ).trim();
		}

		if ( line.startsWith( 'data:' ) ) {
			dataLines.push( line.slice( 5 ).trimStart() );
		}
	} );

	if ( ! dataLines.length ) {
		return null;
	}

	return {
		event,
		data: JSON.parse( dataLines.join( '\n' ) ),
	};
};

const defaultHtmlBlocksToPreview = ( blocks ) =>
	blocks.map( ( block ) => ( {
		...block,
		attributes:
			block.name === 'core/html'
				? { ...block.attributes, preview: true }
				: block.attributes,
		innerBlocks: block.innerBlocks?.length
			? defaultHtmlBlocksToPreview( block.innerBlocks )
			: block.innerBlocks,
	} ) );

function AiEditPanel( { blockName, clientId, attributes } ) {
	const [ prompt, setPrompt ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ streamText, setStreamText ] = useState( '' );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const { replaceBlocks } = useDispatch( 'core/block-editor' );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );
	const block = useSelect(
		( select ) => select( 'core/block-editor' ).getBlock( clientId ),
		[ clientId ]
	);
	const postContext = useSelect( ( select ) => {
		const editor = select( 'core/editor' );

		return {
			postId: editor.getCurrentPostId?.(),
			postType: editor.getCurrentPostType?.(),
			title: editor.getEditedPostAttribute( 'title' ),
			content: editor.getEditedPostAttribute( 'content' ),
		};
	}, [] );

	const serializedBlock = block ? serialize( [ block ] ) : '';
	const existingCode = SANDBOX_BLOCK_NAMES.includes( blockName )
		? JSON.stringify(
				{
					title: attributes.title,
					height: attributes.height,
					html: attributes.html,
					css: attributes.css,
					js: attributes.js,
				},
				null,
				2
		  )
		: attributes.content || serializedBlock;

	const refineBlock = async () => {
		const trimmedPrompt = prompt.trim();

		if ( ! trimmedPrompt ) {
			setError( __( 'Enter an edit prompt first.', 'gutenwright' ) );
			return;
		}

		setIsGenerating( true );
		setError( '' );
		setStreamText( '' );

		try {
			const response = await fetch(
				getRestUrl( '/gutenwright/v1/generate-stream' ),
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': getRestNonce(),
					},
					body: JSON.stringify( {
						prompt: `${ trimmedPrompt }\n\nEdit the existing ${ blockName } block below. Return the complete updated replacement block, not just a diff.`,
						postId: postContext.postId,
						context: {
							...postContext,
							editMode: true,
							sandboxGenerationDisabled:
								isSandboxGenerationDisallowed,
							seamlessMode: isSeamlessModeEnabled,
							existingBlock: {
								name: blockName,
								serialized: serializedBlock,
								code: existingCode,
							},
						},
					} ),
				}
			);

			if ( ! response.ok || ! response.body?.getReader ) {
				throw new Error(
					__( 'The AI edit request failed.', 'gutenwright' )
				);
			}

			const reader = response.body.getReader();
			const decoder = new window.TextDecoder();
			let buffer = '';
			let finalResponse = null;

			const handleRawEvent = ( rawEvent ) => {
				const parsedEvent = parseServerSentEvent( rawEvent );

				if ( ! parsedEvent ) {
					return;
				}

				if ( parsedEvent.event === 'token' ) {
					setStreamText(
						( current ) =>
							current + ( parsedEvent.data.token || '' )
					);
				}

				if ( parsedEvent.event === 'error' ) {
					throw new Error( parsedEvent.data.message );
				}

				if ( parsedEvent.event === 'final' ) {
					finalResponse = parsedEvent.data;
				}
			};

			while ( true ) {
				const { value, done } = await reader.read();

				if ( done ) {
					break;
				}

				buffer += decoder.decode( value, { stream: true } );
				const rawEvents = buffer.split( /\r?\n\r?\n/ );
				buffer = rawEvents.pop() || '';
				rawEvents.forEach( handleRawEvent );
			}

			if ( buffer.trim() ) {
				handleRawEvent( buffer );
			}

			if ( ! finalResponse ) {
				throw new Error(
					__( 'The AI edit did not return blocks.', 'gutenwright' )
				);
			}

			const parsedBlocks = defaultHtmlBlocksToPreview(
				parse( finalResponse.serializedBlocks || '' )
			);

			if ( ! parsedBlocks.length ) {
				throw new Error(
					__(
						'The AI edit returned no insertable blocks.',
						'gutenwright'
					)
				);
			}

			replaceBlocks( clientId, parsedBlocks );
			createSuccessNotice(
				__( 'Block updated with AI.', 'gutenwright' ),
				{
					type: 'snackbar',
				}
			);
		} catch ( apiError ) {
			const message =
				apiError?.message || __( 'The AI edit failed.', 'gutenwright' );

			setError( message );
			createErrorNotice( message, { type: 'snackbar' } );
		} finally {
			setIsGenerating( false );
		}
	};

	return (
		<div className="gutenwright-ai-edit-panel">
			{ error ? (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( '' ) }
				>
					{ error }
				</Notice>
			) : null }
			<TextareaControl
				label={ __( 'Edit this block with AI', 'gutenwright' ) }
				help={ __(
					'The current block code is sent as context.',
					'gutenwright'
				) }
				value={ prompt }
				rows={ 3 }
				onChange={ setPrompt }
				disabled={ isGenerating }
			/>
			<Button
				variant="secondary"
				onClick={ refineBlock }
				disabled={ isGenerating || ! prompt.trim() }
			>
				{ isGenerating
					? __( 'Editing…', 'gutenwright' )
					: __( 'Update Block with AI', 'gutenwright' ) }
			</Button>
			{ isGenerating ? <Spinner /> : null }
			{ streamText ? (
				<pre className="gutenwright-prompt-block__stream">
					{ streamText }
				</pre>
			) : null }
		</div>
	);
}

addFilter(
	'editor.BlockEdit',
	'gutenwright/ai-edit-existing-block',
	( BlockEdit ) => ( props ) => {
		const isEditableGeneratedBlock =
			props.isSelected &&
			( props.name === 'core/html' ||
				( SANDBOX_BLOCK_NAMES.includes( props.name ) &&
					! isSandboxGenerationDisallowed ) );

		return (
			<>
				<BlockEdit { ...props } />
				{ isEditableGeneratedBlock ? (
					<AiEditPanel
						blockName={ props.name }
						clientId={ props.clientId }
						attributes={ props.attributes }
					/>
				) : null }
			</>
		);
	}
);

const sandboxBlockSettings = {
	apiVersion: 3,
	title: __( 'AI Sandboxed Content', 'gutenwright' ),
	category: 'widgets',
	icon: 'editor-code',
	attributes: {
		title: {
			type: 'string',
			default: __( 'AI generated interactive content', 'gutenwright' ),
		},
		html: {
			type: 'string',
			default: '',
		},
		css: {
			type: 'string',
			default: '',
		},
		js: {
			type: 'string',
			default: '',
		},
		height: {
			type: 'number',
			default: 640,
		},
	},
	supports: {
		html: false,
	},
	edit: SandboxEdit,
	save() {
		return null;
	},
};

registerBlockType( SANDBOX_BLOCK_NAME, sandboxBlockSettings );
registerBlockType( LEGACY_SANDBOX_BLOCK_NAME, {
	...sandboxBlockSettings,
	title: __( 'Legacy AI Sandboxed Content', 'gutenwright' ),
} );
