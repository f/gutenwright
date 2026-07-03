=== Gutenwright ===
Contributors: f
Tags: block-editor, ai, gutenberg, openai, blocks
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Prompt-powered Gutenberg composition for WordPress. Generate real blocks from natural language and post context.

== Description ==

Gutenwright is an experimental block editor plugin that turns natural language prompts into real Gutenberg blocks.

= Naming notice =

This project was formerly named ‘pressmind’. It has been renamed to avoid a naming conflict with the registered trademark of Pressmind GmbH. This project has never been affiliated with, endorsed by, sponsored by, or connected to Pressmind GmbH or its products.

Add the Gutenwright block with `/gutenwright`, describe the section or block you want, and Gutenwright sends the prompt plus bounded post context to your configured AI provider. The response is parsed as serialized Gutenberg block markup and inserted back into the editor.

Gutenwright can generate:

* Summaries, tables, callouts, lists, and structured sections.
* Custom HTML and static SVG diagrams.
* Org charts and visual explanations.
* Sandboxed interactive widgets that need JavaScript or scoped CSS.
* Optional generated images imported into the WordPress Media Library.

Gutenwright also supports editing selected generated HTML or sandbox blocks with AI. The current block code is sent as context so the model can return a complete replacement block.

= External services =

Gutenwright connects to external AI provider APIs when a user clicks a generation button in the editor. Nothing is sent automatically.

By default, Gutenwright is configured for OpenAI-compatible chat completions. Site administrators may configure:

* API endpoint, for example `https://api.openai.com/v1/chat/completions`
* API key
* Text model
* Optional image generation through `https://api.openai.com/v1/images/generations`

Data sent to the configured provider may include:

* The user's prompt.
* Bounded post/editor context, such as title, excerpt, current content, and selected block markup.
* Existing HTML, CSS, or JavaScript when editing a generated sandbox block.
* Image prompts when image generation is enabled.

Provider responses are parsed, sanitized, and converted into blocks before being returned to the editor. Generated images are imported into the WordPress Media Library before insertion.

OpenAI terms and policies:

* Terms of use: https://openai.com/policies/terms-of-use/
* Privacy policy: https://openai.com/policies/privacy-policy/

On WordPress versions that include the Connectors API, Gutenwright can use a selected AI connector's API key instead of its own custom API key field.

== Installation ==

1. Upload the `gutenwright` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from the release page.
2. Activate Gutenwright through the Plugins screen in WordPress.
3. Go to Settings > Gutenwright.
4. Choose a credentials source:
   * Custom API key.
   * WordPress Connector, if the Connectors API is available.
5. Configure the endpoint, model, and optional image generation settings.
6. Open the block editor and add the Gutenwright block with `/gutenwright`.

== Frequently Asked Questions ==

= Does Gutenwright generate blocks automatically? =

No. Gutenwright only calls the configured AI provider after a user clicks a generation or edit button.

= Does Gutenwright expose my API key in JavaScript? =

No. API keys are used only server-side in WordPress REST API handlers.

= Can Gutenwright use WordPress Connectors API? =

Yes. On WordPress versions where the Connectors API is available, Gutenwright can use an API-key AI connector as its credential source. Gutenwright still lets you choose the text model name.

= What happens to generated JavaScript? =

Generated content that requires scripts, style tags, scoped CSS, or event handlers is isolated in a sandboxed iframe block by default.

= What is seamless mode? =

Seamless mode is an opt-in setting that disables iframe sandboxing and injects generated HTML, CSS, and JavaScript directly into the page. It requires administrator consent and is disabled when `DISALLOW_UNFILTERED_HTML` is enabled.

= Can Gutenwright generate images? =

Yes, if image generation is enabled in Settings > Gutenwright. Generated images are imported into the WordPress Media Library before being inserted as image blocks.

= Is this production ready? =

Gutenwright is experimental. Review generated content and code before publishing.

== Screenshots ==

1. Gutenwright prompt block in the WordPress block editor.
2. Prefilled demo prompts imported by the Playground blueprint.
3. Sandboxed interactive block output.
4. Settings screen with custom credentials and Connector support.

== Changelog ==

= 0.0.8 =
* Renamed the project to Gutenwright and refreshed public branding.

= 0.0.7 =
* Added opt-in seamless mode for directly injected generated HTML, CSS, and JavaScript when unfiltered HTML is allowed.
* Disabled seamless mode when `DISALLOW_UNFILTERED_HTML` is enabled.
* Improved Connector endpoint display and automatic endpoint selection for Google/Gemini providers.
* Moved response format into Advanced settings and added automatic response format selection by model/provider.

= 0.0.6 =
* Respect `DISALLOW_UNFILTERED_HTML` by disabling AI-generated sandbox iframe output when the site policy is enabled.
* Reject generated sandbox blocks and scripted/style-tagged HTML before insertion when sandbox generation is disabled.
* Show a non-executing warning for existing sandbox blocks while sandbox generation is disabled.

= 0.0.5 =
* Added a longform mental-health interactive news example post that mimics Gutenwright output (`examples/mental-health-interactive-news.html`).
* Added a dedicated Playground blueprint (`blueprint-mental-health.json`) that imports the example as a Gutenberg post.
* Added an animated example output GIF to the README and a one-click Playground link below it.

= 0.0.4 =
* Improved multi-provider compatibility for AI text generation.
* Improved reliability of image generation across providers.
* Internal refactors in the AI provider, REST controller, and settings layers.

= 0.0.3 =
* Added explicit credential source selection for custom API keys or WordPress Connectors API.
* Kept model selection available when using a connector.
* Added stable and nightly Playground blueprints.
* Improved settings UI behavior.
* Added WordPress.org plugin directory readme metadata.

= 0.0.2 =
* Added demo post import for WordPress Playground.
* Added text-to-Gutenwright block transforms.
* Improved sandbox iframe auto-height behavior.
* Improved generated image import into the Media Library.

= 0.0.1 =
* Initial experimental release.
* Added prompt-to-block generation.
* Added streaming editor feedback.
* Added sandboxed interactive blocks.
* Added optional image generation.

== Upgrade Notice ==

= 0.0.8 =
Renames the project to Gutenwright and refreshes public branding.

= 0.0.7 =
Adds opt-in seamless rendering, provider-aware endpoint display, and automatic response format selection.

= 0.0.6 =
Disables AI-generated sandbox iframe output when `DISALLOW_UNFILTERED_HTML` is enabled for the site.

= 0.0.5 =
Adds a longform interactive example post, a Playground blueprint that imports it, and an example output GIF in the README.

= 0.0.4 =
Improves multi-provider compatibility and image generation reliability.

= 0.0.3 =
Adds explicit credential source selection and improves support for WordPress Connectors API.
