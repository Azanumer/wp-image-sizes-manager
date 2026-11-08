<?php
/**
 * Plugin Name: WP Image Sizes Manager
 * Plugin URI: https://github.com/Azanumer/wp-image-sizes-manager
 * Description: See every registered image size, add your own custom sizes, disable built-in sizes you never use, and regenerate thumbnails for existing uploads — all from one page.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-image-sizes-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WISM_VERSION', '1.0.0' );
define( 'WISM_OPTION', 'wism_settings' );
/** How many attachments one regeneration request processes. */
define( 'WISM_REGEN_BATCH', 25 );

/** Built-in WordPress image sizes (the ones in Settings → Media). */
function wism_builtin_sizes() {
	return array( 'thumbnail', 'medium', 'medium_large', 'large' );
}

function wism_get_settings() {
	$defaults = array(
		'custom'   => array(), // slug => [ 'width' => int, 'height' => int, 'crop' => bool ]
		'disabled' => array(), // built-in slugs disabled via intermediate_image_sizes
	);
	$saved = get_option( WISM_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		return $defaults;
	}
	return array_merge( $defaults, $saved );
}

/** Register custom sizes early so they apply to new uploads. */
add_action( 'after_setup_theme', 'wism_register_custom_sizes' );
function wism_register_custom_sizes() {
	$settings = wism_get_settings();
	foreach ( (array) $settings['custom'] as $slug => $size ) {
		add_image_size(
			sanitize_key( $slug ),
			(int) $size['width'],
			(int) $size['height'],
			! empty( $size['crop'] )
		);
	}
}

/** Drop disabled built-in sizes from new uploads. */
add_filter( 'intermediate_image_sizes', 'wism_filter_intermediate_sizes' );
function wism_filter_intermediate_sizes( $sizes ) {
	$settings = wism_get_settings();
	if ( empty( $settings['disabled'] ) ) {
		return $sizes;
	}
	return array_values( array_diff( $sizes, (array) $settings['disabled'] ) );
}

/**
 * All registered sizes with dimensions: slug => [ width, height, crop ].
 * Core sizes come from the options table; additional sizes from the global.
 */
function wism_all_sizes() {
	global $_wp_additional_image_sizes;
	$out = array();
	foreach ( wism_builtin_sizes() as $slug ) {
		$out[ $slug ] = array(
			'width'  => (int) get_option( $slug . '_size_w', 0 ),
			'height' => (int) get_option( $slug . '_size_h', 0 ),
			'crop'   => (bool) get_option( $slug . '_crop', false ),
			'core'   => true,
		);
	}
	if ( ! empty( $_wp_additional_image_sizes ) && is_array( $_wp_additional_image_sizes ) ) {
		foreach ( $_wp_additional_image_sizes as $slug => $size ) {
			$out[ $slug ] = array(
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
				'crop'   => ! empty( $size['crop'] ),
				'core'   => false,
			);
		}
	}
	return $out;
}

add_action( 'admin_menu', 'wism_register_menu' );
function wism_register_menu() {
	add_options_page(
		__( 'Image Sizes', 'wp-image-sizes-manager' ),
		__( 'Image Sizes', 'wp-image-sizes-manager' ),
		'manage_options',
		'wism-image-sizes',
		'wism_render_page'
	);
}

function wism_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-image-sizes-manager' ) );
	}

	$notice   = '';
	$error    = '';
	$settings = wism_get_settings();

	// ---- Add a custom size ---------------------------------------------
	if ( isset( $_POST['wism_add'] ) && check_admin_referer( 'wism_save' ) ) {
		$slug   = isset( $_POST['wism_slug'] ) ? sanitize_key( wp_unslash( $_POST['wism_slug'] ) ) : '';
		$width  = isset( $_POST['wism_width'] ) ? (int) $_POST['wism_width'] : 0;
		$height = isset( $_POST['wism_height'] ) ? (int) $_POST['wism_height'] : 0;
		$crop   = ! empty( $_POST['wism_crop'] );

		$existing = wism_all_sizes();
		if ( '' === $slug ) {
			$error = __( 'A slug is required.', 'wp-image-sizes-manager' );
		} elseif ( isset( $existing[ $slug ] ) ) {
			$error = __( 'An image size with that slug already exists.', 'wp-image-sizes-manager' );
		} elseif ( $width <= 0 || $height <= 0 ) {
			$error = __( 'Width and height must both be positive numbers.', 'wp-image-sizes-manager' );
		} elseif ( $width > 5000 || $height > 5000 ) {
			$error = __( 'Width and height are capped at 5000px.', 'wp-image-sizes-manager' );
		} else {
			$settings['custom'][ $slug ] = array(
				'width'  => $width,
				'height' => $height,
				'crop'   => $crop,
			);
			update_option( WISM_OPTION, $settings );
			add_image_size( $slug, $width, $height, $crop ); // Live for this request too.
			/* translators: %s: image size slug */
			$notice = sprintf( __( 'Image size "%s" added. It applies to new uploads — use Regenerate below for existing images.', 'wp-image-sizes-manager' ), $slug );
		}
	}

	// ---- Delete a custom size ------------------------------------------
	if ( isset( $_POST['wism_delete'] ) && check_admin_referer( 'wism_save' ) ) {
		$slug = isset( $_POST['wism_slug'] ) ? sanitize_key( wp_unslash( $_POST['wism_slug'] ) ) : '';
		if ( ! isset( $settings['custom'][ $slug ] ) ) {
			$error = __( 'Only custom sizes can be deleted.', 'wp-image-sizes-manager' );
		} else {
			unset( $settings['custom'][ $slug ] );
			update_option( WISM_OPTION, $settings );
			/* translators: %s: image size slug */
			$notice = sprintf( __( 'Image size "%s" deleted. Existing thumbnails on disk are kept — they are harmless.', 'wp-image-sizes-manager' ), $slug );
		}
	}

	// ---- Toggle disabled built-in sizes --------------------------------
	if ( isset( $_POST['wism_save_disabled'] ) && check_admin_referer( 'wism_save' ) ) {
		$disabled = isset( $_POST['wism_disabled'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['wism_disabled'] ) ) : array();
		$settings['disabled'] = array_values( array_intersect( $disabled, wism_builtin_sizes() ) );
		update_option( WISM_OPTION, $settings );
		$notice = __( 'Disabled sizes saved. They will no longer be generated for new uploads.', 'wp-image-sizes-manager' );
	}

	$sizes       = wism_all_sizes();
	$total_media = (int) wp_count_posts( 'attachment' )->inherit;
	$regen_done  = isset( $_GET['wism_regen_done'] );
	$regen_error = isset( $_GET['wism_regen_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wism_regen_error'] ) ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Image Sizes', 'wp-image-sizes-manager' ); ?></h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<?php if ( $regen_done ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Regeneration complete — all images now have every registered size.', 'wp-image-sizes-manager' ); ?></p></div>
		<?php endif; ?>
		<?php if ( '' !== $regen_error ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $regen_error ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Registered sizes', 'wp-image-sizes-manager' ); ?></h2>
		<table class="widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Slug', 'wp-image-sizes-manager' ); ?></th>
				<th><?php esc_html_e( 'Dimensions', 'wp-image-sizes-manager' ); ?></th>
				<th><?php esc_html_e( 'Crop', 'wp-image-sizes-manager' ); ?></th>
				<th><?php esc_html_e( 'Type', 'wp-image-sizes-manager' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wp-image-sizes-manager' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $sizes as $slug => $size ) : ?>
					<tr>
						<td><code><?php echo esc_html( $slug ); ?></code></td>
						<td><?php echo (int) $size['width']; ?> &times; <?php echo (int) $size['height']; ?> px</td>
						<td><?php echo ! empty( $size['crop'] ) ? esc_html__( 'Hard crop', 'wp-image-sizes-manager' ) : esc_html__( 'Proportional', 'wp-image-sizes-manager' ); ?></td>
						<td>
							<?php
							if ( isset( $settings['custom'][ $slug ] ) ) {
								esc_html_e( 'Custom (this plugin)', 'wp-image-sizes-manager' );
							} elseif ( $size['core'] ) {
								esc_html_e( 'Built-in', 'wp-image-sizes-manager' );
							} else {
								esc_html_e( 'Theme / plugin', 'wp-image-sizes-manager' );
							}
							?>
						</td>
						<td>
							<?php if ( isset( $settings['custom'][ $slug ] ) ) : ?>
								<form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Delete this image size? Existing thumbnails stay on disk.', 'wp-image-sizes-manager' ); ?>');">
									<?php wp_nonce_field( 'wism_save' ); ?>
									<input type="hidden" name="wism_slug" value="<?php echo esc_attr( $slug ); ?>">
									<?php submit_button( __( 'Delete', 'wp-image-sizes-manager' ), 'delete small', 'wism_delete', false ); ?>
								</form>
							<?php else : ?>
								<span class="description">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Add a custom size', 'wp-image-sizes-manager' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'wism_save' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wism_slug"><?php esc_html_e( 'Slug', 'wp-image-sizes-manager' ); ?></label></th>
					<td><input type="text" id="wism_slug" name="wism_slug" class="regular-text" required placeholder="card_thumb">
					<p class="description"><?php esc_html_e( 'Lowercase letters, numbers and underscores. Use it in code as the size name, e.g. the_post_thumbnail( \'card_thumb\' ).', 'wp-image-sizes-manager' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="wism_width"><?php esc_html_e( 'Width × Height', 'wp-image-sizes-manager' ); ?></label></th>
					<td>
						<input type="number" id="wism_width" name="wism_width" min="1" max="5000" required style="width:90px;"> ×
						<input type="number" name="wism_height" min="1" max="5000" required style="width:90px;"> px
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Crop', 'wp-image-sizes-manager' ); ?></th>
					<td><label><input type="checkbox" name="wism_crop" value="1"> <?php esc_html_e( 'Hard crop to exact dimensions (otherwise the image is scaled proportionally to fit inside).', 'wp-image-sizes-manager' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( __( 'Add image size', 'wp-image-sizes-manager' ), 'primary', 'wism_add' ); ?>
		</form>

		<h2><?php esc_html_e( 'Disable built-in sizes', 'wp-image-sizes-manager' ); ?></h2>
		<p><?php esc_html_e( 'WordPress generates every built-in size for every upload, even ones your theme never uses. Disable the ones you don\'t need to save disk space on new uploads.', 'wp-image-sizes-manager' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'wism_save' ); ?>
			<?php foreach ( wism_builtin_sizes() as $slug ) : ?>
				<label style="display:block;margin:4px 0;">
					<input type="checkbox" name="wism_disabled[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $settings['disabled'], true ) ); ?>>
					<code><?php echo esc_html( $slug ); ?></code>
					<span class="description"><?php echo (int) $sizes[ $slug ]['width']; ?> &times; <?php echo (int) $sizes[ $slug ]['height']; ?> px</span>
				</label>
			<?php endforeach; ?>
			<?php submit_button( __( 'Save disabled sizes', 'wp-image-sizes-manager' ), 'secondary', 'wism_save_disabled' ); ?>
		</form>

		<h2><?php esc_html_e( 'Regenerate thumbnails', 'wp-image-sizes-manager' ); ?></h2>
		<p>
			<?php
			/* translators: %d: number of media items */
			printf( esc_html__( '%d images in the Media Library. Regenerating creates every registered size for existing uploads (new uploads already get them). It runs in batches of %d so it never times out.', 'wp-image-sizes-manager' ), $total_media, WISM_REGEN_BATCH );
			?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wism_regenerate&offset=0' ), 'wism_regenerate' ) ); ?>"><?php esc_html_e( 'Regenerate all thumbnails', 'wp-image-sizes-manager' ); ?></a>
		</p>
	</div>
	<?php
}

/** Batch regeneration handler: processes WISM_REGEN_BATCH attachments, then continues. */
add_action( 'admin_post_wism_regenerate', 'wism_handle_regenerate' );
function wism_handle_regenerate() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wism_regenerate' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-image-sizes-manager' ) );
	}
	$offset = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;

	$ids = get_posts( array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_status'    => 'inherit',
		'posts_per_page' => WISM_REGEN_BATCH,
		'offset'         => $offset,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );

	foreach ( $ids as $id ) {
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			continue;
		}
		$meta = wp_generate_attachment_metadata( $id, $file );
		if ( ! is_wp_error( $meta ) && ! empty( $meta ) ) {
			wp_update_attachment_metadata( $id, $meta );
		}
	}

	$done_url = admin_url( 'options-general.php?page=wism-image-sizes&wism_regen_done=1' );
	if ( count( $ids ) < WISM_REGEN_BATCH ) {
		// Fewer than a full batch: nothing left to do.
		wp_safe_redirect( $done_url );
		exit;
	}
	// More to go — auto-continue with the next batch after a 1s pause.
	$next = wp_nonce_url( admin_url( 'admin-post.php?action=wism_regenerate&offset=' . ( $offset + WISM_REGEN_BATCH ) ), 'wism_regenerate' );
	$done = $offset + count( $ids );
	?>
	<!DOCTYPE html>
	<html><head>
		<meta charset="utf-8">
		<meta http-equiv="refresh" content="1;url=<?php echo esc_url( $next ); ?>">
		<title><?php esc_html_e( 'Regenerating thumbnails…', 'wp-image-sizes-manager' ); ?></title>
		<style>body{font-family:sans-serif;padding:40px;color:#1d2327} a{color:#2271b1}</style>
	</head><body>
		<h1><?php esc_html_e( 'Regenerating thumbnails…', 'wp-image-sizes-manager' ); ?></h1>
		<p>
			<?php
			/* translators: %d: number of images processed so far */
			printf( esc_html__( '%d images regenerated so far — continuing automatically…', 'wp-image-sizes-manager' ), (int) $done );
			?>
		</p>
		<p><a href="<?php echo esc_url( $next ); ?>"><?php esc_html_e( 'Continue now', 'wp-image-sizes-manager' ); ?></a></p>
	</body></html>
	<?php
	exit;
}
