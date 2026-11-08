# WP Image Sizes Manager

See every registered image size, add your own custom sizes, disable built-in sizes you never use, and regenerate thumbnails for existing uploads — all from **Settings → Image Sizes**.

## Features

- **Size inventory** — every registered size with dimensions and crop mode: built-in, theme/plugin, and custom sizes from this plugin.
- **Add custom sizes** — slug + width + height + hard-crop toggle, registered via `add_image_size()` for all new uploads.
- **Disable built-in sizes** — stop WordPress generating `thumbnail`, `medium`, `medium_large` or `large` for new uploads and save disk space.
- **Regenerate thumbnails** — batch-regenerates every registered size for existing images, 25 at a time with auto-continue, so it never times out on big libraries.
- **Safe** — only `manage_options` users can open the page; all saves are nonce-checked and sanitized; deleting a size never deletes files from disk.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.
3. Go to **Settings → Image Sizes**.

## Usage

Use a custom size in your theme like any other size:

```php
the_post_thumbnail( 'card_thumb' );
```

## Notes

- Custom sizes apply to **new uploads** automatically; run **Regenerate thumbnails** once to create them for images already in the library.
- Disabling a built-in size only affects new uploads — existing thumbnails stay on disk.
- Uninstalling deletes the single `wism_settings` option; sizes registered by your theme or other plugins are untouched.

## License

MIT — see [LICENSE](LICENSE).
