<?php
/**
 * Admin page template for RW Image Size Manager.
 *
 * Variables available from ism_render_admin_page():
 *   $settings, $all_sizes, $disabled_sizes, $custom_sizes,
 *   $post_type_rules, $registered_cpts, $saved
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap ism-wrap">
	<h1><?php esc_html_e( 'Image Manager', 'image-size-manager' ); ?></h1>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'image-size-manager' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ism_save">
		<?php wp_nonce_field( 'ism_save_settings', 'ism_nonce' ); ?>

		<!-- ═══════════════════════════════════════════════════════════════════
		     TAB NAV
		     ══════════════════════════════════════════════════════════════════ -->
		<nav class="ism-tabs">
			<button type="button" class="ism-tab active" data-target="ism-panel-sizes">
				<?php esc_html_e( 'Registered Sizes', 'image-size-manager' ); ?>
			</button>
			<button type="button" class="ism-tab" data-target="ism-panel-custom">
				<?php esc_html_e( 'Custom Sizes', 'image-size-manager' ); ?>
			</button>
		<?php if ( ! empty( $registered_cpts ) ) : ?>
		<button type="button" class="ism-tab" data-target="ism-panel-cpts">
			<?php esc_html_e( 'Post Types', 'image-size-manager' ); ?>
			</button>
			<?php endif; ?>
			<button type="button" class="ism-tab" data-target="ism-panel-media-log">
				<?php esc_html_e( 'Media Log', 'image-size-manager' ); ?>
			</button>
			<button type="button" class="ism-tab" data-target="ism-panel-seo">
				<?php esc_html_e( 'Image SEO', 'image-size-manager' ); ?>
			</button>
			<button type="button" class="ism-tab" data-target="ism-panel-broken">
				<?php esc_html_e( 'Broken Images', 'image-size-manager' ); ?>
			</button>
			<button type="button" class="ism-tab ism-tab-advanced" data-target="ism-panel-advanced">
				<?php esc_html_e( 'Advanced', 'image-size-manager' ); ?>
			</button>
		</nav>

		<!-- ═══════════════════════════════════════════════════════════════════
		     PANEL 1 – REGISTERED SIZES
		     ══════════════════════════════════════════════════════════════════ -->
		<div id="ism-panel-sizes" class="ism-panel">
			<h2><?php esc_html_e( 'Registered Image Sizes', 'image-size-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Toggle a size on to allow WordPress to generate it on new uploads. Toggle it off to disable generation. You can also override the dimensions and crop setting for built-in sizes.', 'image-size-manager' ); ?>
			</p>

			<table class="widefat ism-sizes-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Enabled', 'image-size-manager' ); ?></th>
						<th><?php esc_html_e( 'Size Key', 'image-size-manager' ); ?></th>
						<th><?php esc_html_e( 'Width (px)', 'image-size-manager' ); ?></th>
						<th><?php esc_html_e( 'Height (px)', 'image-size-manager' ); ?></th>
						<th><?php esc_html_e( 'Crop', 'image-size-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				// Built-in editable sizes
				$editable_builtin = [ 'thumbnail', 'medium', 'medium_large', 'large' ];

				foreach ( $all_sizes as $key => $size ) :
					$is_disabled  = in_array( $key, $disabled_sizes, true );
					$is_builtin   = in_array( $key, $editable_builtin, true );
					$is_custom    = false;
					foreach ( $custom_sizes as $cs ) {
						if ( sanitize_key( $cs['name'] ?? '' ) === $key ) {
							$is_custom = true;
							break;
						}
					}
					$row_class = $is_disabled ? 'ism-row-disabled' : '';
				?>
				<tr class="<?php echo esc_attr( $row_class ); ?>">
					<td>
						<label class="ism-toggle">
							<input
								type="checkbox"
								name="ism_disabled_sizes[]"
								value="<?php echo esc_attr( $key ); ?>"
								<?php checked( $is_disabled ); ?>
								class="ism-disable-toggle"
							>
							<span class="ism-toggle-slider"></span>
						</label>
					</td>
					<td>
						<code><?php echo esc_html( $key ); ?></code>
						<?php if ( $is_builtin ) : ?>
							<span class="ism-badge ism-badge-core"><?php esc_html_e( 'core', 'image-size-manager' ); ?></span>
						<?php elseif ( $is_custom ) : ?>
							<span class="ism-badge ism-badge-custom"><?php esc_html_e( 'custom', 'image-size-manager' ); ?></span>
						<?php else : ?>
							<span class="ism-badge ism-badge-theme"><?php esc_html_e( 'theme/plugin', 'image-size-manager' ); ?></span>
						<?php endif; ?>
					</td>
					<?php if ( $is_builtin ) : ?>
					<td>
						<input
							type="number"
							min="0"
							name="ism_builtin[<?php echo esc_attr( $key ); ?>][width]"
							value="<?php echo esc_attr( $size['width'] ); ?>"
							class="ism-dim-input"
						>
					</td>
					<td>
						<input
							type="number"
							min="0"
							name="ism_builtin[<?php echo esc_attr( $key ); ?>][height]"
							value="<?php echo esc_attr( $size['height'] ); ?>"
							class="ism-dim-input"
						>
					</td>
					<td>
						<input
							type="checkbox"
							name="ism_builtin[<?php echo esc_attr( $key ); ?>][crop]"
							value="1"
							<?php checked( $size['crop'] ); ?>
						>
					</td>
					<?php else : ?>
					<td><?php echo esc_html( $size['width']  ?: '—' ); ?></td>
					<td><?php echo esc_html( $size['height'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $size['crop'] ? '✓' : '—' ); ?></td>
					<?php endif; ?>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="ism-note">
				<?php esc_html_e( 'Note: Disabling a size only prevents future generation. Use a plugin like "Regenerate Thumbnails" to remove already-generated files.', 'image-size-manager' ); ?>
			</p>

			<!-- Max upload dimensions ─────────────────────────────────────── -->
			<hr style="margin: 28px 0;">
			<h3 style="margin-bottom:6px"><?php esc_html_e( 'Max Upload Dimensions', 'image-size-manager' ); ?></h3>
			<p class="description" style="margin-bottom:14px">
				<?php esc_html_e( 'When set, the original uploaded image is resized in-place to fit within these bounds before any thumbnails are generated. WordPress\'s automatic -scaled file is suppressed. Set either field to 0 to leave that axis unconstrained.', 'image-size-manager' ); ?>
			</p>
			<table class="form-table ism-max-upload-table" style="max-width:480px">
				<tr>
					<th scope="row"><label for="ism_max_upload_width"><?php esc_html_e( 'Max Width (px)', 'image-size-manager' ); ?></label></th>
					<td>
						<input
							type="number"
							id="ism_max_upload_width"
							name="ism_max_upload_width"
							min="0"
							step="1"
							value="<?php echo esc_attr( (int) ( $settings['max_upload_width'] ?? 0 ) ); ?>"
							class="ism-dim-input"
						>
						<span class="description"><?php esc_html_e( '0 = no limit', 'image-size-manager' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ism_max_upload_height"><?php esc_html_e( 'Max Height (px)', 'image-size-manager' ); ?></label></th>
					<td>
						<input
							type="number"
							id="ism_max_upload_height"
							name="ism_max_upload_height"
							min="0"
							step="1"
							value="<?php echo esc_attr( (int) ( $settings['max_upload_height'] ?? 0 ) ); ?>"
							class="ism-dim-input"
						>
						<span class="description"><?php esc_html_e( '0 = no limit', 'image-size-manager' ); ?></span>
					</td>
				</tr>
			</table>

		</div><!-- /ism-panel-sizes -->

		<!-- ═══════════════════════════════════════════════════════════════════
		     PANEL 2 – CUSTOM SIZES
		     ══════════════════════════════════════════════════════════════════ -->
		<div id="ism-panel-custom" class="ism-panel" hidden>
			<h2><?php esc_html_e( 'Custom Image Sizes', 'image-size-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Add your own image sizes. They will be registered via add_image_size() on every page load and appear in the Registered Sizes tab.', 'image-size-manager' ); ?>
			</p>

			<table class="widefat ism-custom-table" id="ism-custom-sizes-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Size Key (slug)', 'image-size-manager' ); ?></th>
						<th><?php esc_html_e( 'Width (px)', 'image-size-manager' ); ?></th>
						<th><?php esc_html_e( 'Height (px)', 'image-size-manager' ); ?></th>
						<th><?php esc_html_e( 'Hard Crop', 'image-size-manager' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ism-custom-sizes-body">
				<?php foreach ( $custom_sizes as $i => $cs ) : ?>
				<tr class="ism-custom-row">
					<td>
						<input
							type="text"
							name="ism_custom_sizes[<?php echo (int) $i; ?>][name]"
							value="<?php echo esc_attr( $cs['name'] ?? '' ); ?>"
							placeholder="my-size-key"
							class="regular-text"
							required
						>
					</td>
					<td>
						<input
							type="number"
							min="0"
							name="ism_custom_sizes[<?php echo (int) $i; ?>][width]"
							value="<?php echo esc_attr( $cs['width'] ?? 0 ); ?>"
							class="ism-dim-input"
						>
					</td>
					<td>
						<input
							type="number"
							min="0"
							name="ism_custom_sizes[<?php echo (int) $i; ?>][height]"
							value="<?php echo esc_attr( $cs['height'] ?? 0 ); ?>"
							class="ism-dim-input"
						>
					</td>
					<td>
						<input
							type="checkbox"
							name="ism_custom_sizes[<?php echo (int) $i; ?>][crop]"
							value="1"
							<?php checked( ! empty( $cs['crop'] ) && '1' === $cs['crop'] ); ?>
						>
					</td>
					<td>
						<button type="button" class="button ism-remove-row">
							<?php esc_html_e( 'Remove', 'image-size-manager' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<button type="button" class="button" id="ism-add-custom-size">
					+ <?php esc_html_e( 'Add Size', 'image-size-manager' ); ?>
				</button>
			</p>
		</div><!-- /ism-panel-custom -->

		<!-- ═══════════════════════════════════════════════════════════════════
		     PANEL 3 – POST TYPES
		     ══════════════════════════════════════════════════════════════════ -->
		<?php if ( ! empty( $registered_cpts ) ) : ?>
		<div id="ism-panel-cpts" class="ism-panel ism-panel-cpts" hidden>
			<div class="ism-cpt-layout">

				<!-- Sidebar nav -->
				<nav class="ism-cpt-sidebar">
					<?php $first_cpt = true; foreach ( $registered_cpts as $cpt_key => $cpt_info ) : ?>
					<button
						type="button"
						class="ism-cpt-nav-item<?php echo $first_cpt ? ' active' : ''; ?>"
						data-cpt="<?php echo esc_attr( $cpt_key ); ?>"
					>
						<?php echo esc_html( $cpt_info['label'] ); ?>
						<?php if ( 'product' === $cpt_key ) : ?>
							<span class="ism-badge ism-badge-woo">WC</span>
						<?php endif; ?>
					</button>
					<?php $first_cpt = false; endforeach; ?>
				</nav><!-- /.ism-cpt-sidebar -->

				<!-- Settings panels -->
				<div class="ism-cpt-panels">
					<?php $first_cpt = true; foreach ( $registered_cpts as $cpt_key => $cpt_info ) :
						$rule             = $post_type_rules[ $cpt_key ] ?? [];
						$restrict_enabled = '1' === ( $rule['restrict_enabled'] ?? '0' );
						$allowed_sizes    = (array) ( $rule['allowed_sizes'] ?? [] );
						$delete_images    = '1' === ( $rule['delete_images'] ?? '0' );
					?>
					<div
						class="ism-cpt-panel"
						data-cpt="<?php echo esc_attr( $cpt_key ); ?>"
						<?php echo $first_cpt ? '' : 'hidden'; ?>
					>
						<h2>
							<?php echo esc_html( $cpt_info['label'] ); ?>
							<code style="font-size:13px;font-weight:400"><?php echo esc_html( $cpt_key ); ?></code>
						</h2>

						<!-- Image size restriction card -->
						<div class="ism-card">
							<h3><?php esc_html_e( 'Image Size Restriction', 'image-size-manager' ); ?></h3>
							<p class="description">
								<?php printf(
									/* translators: %s: post type singular label */
									esc_html__( 'When enabled, only the checked sizes will be generated when an image is uploaded to a &#8220;%s&#8221; post.', 'image-size-manager' ),
									esc_html( $cpt_info['label'] )
								); ?>
							</p>

							<label class="ism-master-toggle">
								<input
									type="checkbox"
									name="ism_cpt_rules[<?php echo esc_attr( $cpt_key ); ?>][restrict_enabled]"
									value="1"
									class="ism-cpt-restrict-toggle"
									<?php checked( $restrict_enabled ); ?>
								>
								<?php esc_html_e( 'Restrict image sizes for this post type', 'image-size-manager' ); ?>
							</label>

							<div class="ism-cpt-sizes-picker" <?php echo $restrict_enabled ? '' : 'hidden'; ?>>
								<table class="widefat ism-cpt-sizes-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Generate', 'image-size-manager' ); ?></th>
											<th><?php esc_html_e( 'Size Key', 'image-size-manager' ); ?></th>
											<th><?php esc_html_e( 'Width', 'image-size-manager' ); ?></th>
											<th><?php esc_html_e( 'Height', 'image-size-manager' ); ?></th>
											<th><?php esc_html_e( 'Crop', 'image-size-manager' ); ?></th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ( $all_sizes as $sz_key => $sz_data ) :
										$globally_disabled = in_array( $sz_key, $disabled_sizes, true );
										$is_checked        = ! $globally_disabled && in_array( $sz_key, $allowed_sizes, true );
									?>
									<tr<?php echo $globally_disabled ? ' class="ism-row-disabled"' : ''; ?>>
										<td>
											<input
												type="checkbox"
												name="ism_cpt_rules[<?php echo esc_attr( $cpt_key ); ?>][allowed_sizes][]"
												value="<?php echo esc_attr( $sz_key ); ?>"
												<?php checked( $is_checked ); ?>
												<?php disabled( $globally_disabled ); ?>
											>
										</td>
										<td><code><?php echo esc_html( $sz_key ); ?></code></td>
										<td><?php echo esc_html( $sz_data['width']  ?: '—' ); ?></td>
										<td><?php echo esc_html( $sz_data['height'] ?: '—' ); ?></td>
										<td><?php echo $sz_data['crop'] ? '✓' : '—'; ?></td>
									</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div><!-- /.ism-cpt-sizes-picker -->
						</div><!-- /.ism-card -->

						<!-- Auto-delete images card -->
						<div class="ism-card">
							<h3><?php esc_html_e( 'Auto-Delete Images', 'image-size-manager' ); ?></h3>
							<p class="description">
								<?php printf(
									/* translators: %s: post type singular label */
									esc_html__( 'When a &#8220;%s&#8221; post is permanently deleted from trash, all attached images will also be permanently deleted from the Media Library.', 'image-size-manager' ),
									esc_html( $cpt_info['label'] )
								); ?>
							</p>

							<label class="ism-master-toggle">
								<input
									type="checkbox"
									name="ism_cpt_rules[<?php echo esc_attr( $cpt_key ); ?>][delete_images]"
									value="1"
									<?php checked( $delete_images ); ?>
								>
								<?php printf(
									/* translators: %s: post type singular label */
									esc_html__( 'Delete all images when a &#8220;%s&#8221; is permanently deleted', 'image-size-manager' ),
									esc_html( $cpt_info['label'] )
								); ?>
							</label>

							<p class="ism-warning">
								⚠️ <?php esc_html_e( 'This action is permanent and cannot be undone. Images shared with other posts will also be deleted.', 'image-size-manager' ); ?>
							</p>
						</div><!-- /.ism-card -->

						<!-- Regenerate thumbnails card -->
						<div class="ism-card ism-regen-card" data-cpt="<?php echo esc_attr( $cpt_key ); ?>">
							<h3><?php esc_html_e( 'Regenerate Thumbnails', 'image-size-manager' ); ?></h3>
							<p class="description">
								<?php printf(
									/* translators: %s: post type singular label */
									esc_html__( 'Loops through every &#8220;%s&#8221; image, regenerates only the sizes allowed above, and deletes any old size files that are no longer needed. Run this once after changing size settings on an existing site.', 'image-size-manager' ),
									esc_html( $cpt_info['label'] )
								); ?>
							</p>
							<p class="ism-warning">
								⚠️ <?php esc_html_e( 'Old image size files will be permanently deleted from the server. This cannot be undone.', 'image-size-manager' ); ?>
							</p>

							<div class="ism-regen-controls">
								<button
									type="button"
									class="button button-primary ism-regen-start"
									data-cpt="<?php echo esc_attr( $cpt_key ); ?>"
									data-label="<?php echo esc_attr( $cpt_info['label'] ); ?>"
								>
									<?php esc_html_e( 'Start Regeneration', 'image-size-manager' ); ?>
								</button>
								<button
									type="button"
									class="button ism-regen-cancel"
									style="display:none"
								>
									<?php esc_html_e( 'Cancel', 'image-size-manager' ); ?>
								</button>
							</div>

							<div class="ism-regen-resume-bar" style="display:none">
								<span class="ism-regen-resume-info"></span>
								<button type="button" class="button button-primary ism-regen-resume-btn"><?php esc_html_e( 'Resume', 'image-size-manager' ); ?></button>
								<button type="button" class="button ism-regen-fresh-btn"><?php esc_html_e( 'Start Fresh', 'image-size-manager' ); ?></button>
							</div>

							<div class="ism-progress-wrap" style="display:none">
								<div class="ism-progress-bar-track">
									<div class="ism-progress-bar-fill"></div>
								</div>
								<p class="ism-progress-status"></p>
							</div>

							<ul class="ism-regen-log" style="display:none"></ul>
						</div><!-- /.ism-regen-card -->

					</div><!-- /.ism-cpt-panel -->
					<?php $first_cpt = false; endforeach; ?>
				</div><!-- /.ism-cpt-panels -->

			</div><!-- /.ism-cpt-layout -->
		</div><!-- /ism-panel-cpts -->
		<?php endif; ?>

		<!-- ═══════════════════════════════════════════════════════════════════
		     PANEL 4 – MEDIA LOG
		     ══════════════════════════════════════════════════════════════════ -->
		<div id="ism-panel-media-log" class="ism-panel" hidden>
			<div class="ism-card">
				<h2><?php esc_html_e( 'Media Log', 'image-size-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Browse all uploaded images and the size variations generated for each one.', 'image-size-manager' ); ?></p>

				<div class="ism-log-toolbar">
					<input type="text" id="ism-log-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by filename…', 'image-size-manager' ); ?>" />
					<button type="button" class="button" id="ism-log-search-btn"><?php esc_html_e( 'Search', 'image-size-manager' ); ?></button>
					<button type="button" class="button" id="ism-log-clear-btn"><?php esc_html_e( 'Clear', 'image-size-manager' ); ?></button>
					<span class="ism-log-summary"></span>
				</div>

				<div id="ism-log-results">
					<p class="ism-log-loading" style="display:none"><?php esc_html_e( 'Loading…', 'image-size-manager' ); ?></p>
					<div class="ism-log-list"></div>
				</div>

				<div class="ism-log-pagination">
					<button type="button" class="button" id="ism-log-prev" disabled><?php esc_html_e( '← Prev', 'image-size-manager' ); ?></button>
					<span class="ism-log-page-info"></span>
					<button type="button" class="button" id="ism-log-next" disabled><?php esc_html_e( 'Next →', 'image-size-manager' ); ?></button>
				</div>
			</div>
		</div><!-- /ism-panel-media-log -->

		<!-- ═══════════════════════════════════════════════════════════════════
		     PANEL – IMAGE SEO
		     ══════════════════════════════════════════════════════════════════ -->
		<?php
		$ism_has_key      = ism_ai_has_key();
		$ism_index_status = ism_usage_index_status();
		$ism_index_built  = (bool) $ism_index_status['built_at'];
		?>
		<div id="ism-panel-seo" class="ism-panel" hidden>

			<div class="ism-card">
				<h2><?php esc_html_e( 'Image SEO', 'image-size-manager' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Audit and populate image titles, alt text and descriptions, using the pages each image actually appears on as context. Everything except generation works without an API key.', 'image-size-manager' ); ?>
				</p>
			</div>

			<!-- API key ─────────────────────────────────────────────────── -->
			<div class="ism-card" style="margin-top:18px">
				<h4><?php esc_html_e( 'Anthropic API Key', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Required only for generation. Stored in its own option and never sent to the browser. Leave blank to keep the existing key.', 'image-size-manager' ); ?>
				</p>
				<p>
					<input type="password" name="ism_api_key" class="regular-text" autocomplete="off"
						placeholder="<?php echo esc_attr( $ism_has_key ? __( 'A key is saved — leave blank to keep it', 'image-size-manager' ) : 'sk-ant-…' ); ?>" />
					<span class="ism-key-state">
						<?php if ( $ism_has_key ) : ?>
							<span style="color:#1d7e2d;font-weight:500"><?php esc_html_e( '✓ Key saved', 'image-size-manager' ); ?></span>
						<?php else : ?>
							<span style="color:#996800;font-weight:500"><?php esc_html_e( 'No key — generation disabled', 'image-size-manager' ); ?></span>
						<?php endif; ?>
					</span>
				</p>
				<p class="ism-key-actions" <?php echo $ism_has_key ? '' : 'style="display:none"'; ?>>
					<button type="button" class="button button-link-delete" id="ism-remove-key">
						<?php esc_html_e( 'Remove stored key', 'image-size-manager' ); ?>
					</button>
					<span class="ism-key-remove-status description"></span>
				</p>
			</div>

			<!-- Generation settings ─────────────────────────────────────── -->
			<div class="ism-card" style="margin-top:18px">
				<h4><?php esc_html_e( 'Generation Settings', 'image-size-manager' ); ?></h4>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ism-ai-model"><?php esc_html_e( 'Model', 'image-size-manager' ); ?></label></th>
						<td>
							<?php $ism_current_model = ism_ai_get_model(); ?>
							<select name="ism_ai_model" id="ism-ai-model">
								<?php foreach ( ism_ai_models() as $ism_mid => $ism_m ) : ?>
									<option value="<?php echo esc_attr( $ism_mid ); ?>" <?php selected( $ism_current_model, $ism_mid ); ?>>
										<?php
										printf(
											'%s — about $%s per 100 images',
											esc_html( $ism_m['label'] ),
											esc_html( number_format( ism_ai_cost_per_100( $ism_mid ), 2 ) )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php foreach ( ism_ai_models() as $ism_mid => $ism_m ) : ?>
								<p class="description ism-model-note" data-model="<?php echo esc_attr( $ism_mid ); ?>"
									<?php echo $ism_current_model === $ism_mid ? '' : 'style="display:none"'; ?>>
									<?php echo esc_html( $ism_m['note'] ); ?>
									<br>
									<?php
									printf(
										/* translators: 1: input price, 2: output price */
										esc_html__( 'Billed at $%1$s per million tokens you send and $%2$s per million it writes back. A typical image sends about 700 and gets back about 150.', 'image-size-manager' ),
										esc_html( number_format( $ism_m['in'], 2 ) ),
										esc_html( number_format( $ism_m['out'], 2 ) )
									);
									?>
								</p>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ism-context-max-chars"><?php esc_html_e( 'Page context', 'image-size-manager' ); ?></label></th>
						<td>
							<input type="number" name="ism_context_max_chars" id="ism-context-max-chars" class="small-text"
								min="100" max="20000" step="100"
								value="<?php echo esc_attr( (string) ism_context_max_chars() ); ?>" />
							<?php esc_html_e( 'characters', 'image-size-manager' ); ?>
							<p class="description">
								<?php esc_html_e( 'Total budget for page text across every page an image appears on. Every page is always named in the prompt; this controls how much of their body copy is included. Spent in relevance order — a page using the image as its featured image goes first, then body and builder content, then custom fields, with header and footer templates last.', 'image-size-manager' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'More context costs more per image. 1000 is a reasonable start; raise it if output reads generic.', 'image-size-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" class="button" id="ism-advanced-toggle" aria-expanded="false">
						<?php esc_html_e( 'Advanced AI settings', 'image-size-manager' ); ?>
						<span class="ism-advanced-caret">▸</span>
					</button>
				</p>

				<?php
				$ism_weights = ism_ai_get_weights();
				$ism_labels  = [
					'image'    => __( 'Looking at the image itself', 'image-size-manager' ),
					'prompt'   => __( 'Your instructions and keywords', 'image-size-manager' ),
					'context'  => __( 'The pages the image appears on', 'image-size-manager' ),
					'metadata' => __( 'Existing title, alt text and caption', 'image-size-manager' ),
				];
				$ism_hints = [
					'image'    => __( 'What is actually visible. Set to 0 to describe without looking — much cheaper, and usually much worse.', 'image-size-manager' ),
					'prompt'   => __( 'Only counts if you write something below. 0 ignores it entirely.', 'image-size-manager' ),
					'context'  => __( 'Page titles, keywords and body copy from every page using this image.', 'image-size-manager' ),
					'metadata' => __( 'Lets generation improve on what is there rather than ignore it.', 'image-size-manager' ),
				];
				?>
				<div id="ism-advanced-panel" class="ism-advanced-panel" hidden>

					<p class="description ism-advanced-explainer">
						<?php esc_html_e( 'These percentages do two things. A source set to 0 is left out of the request completely — no image sent, no page context, no existing metadata. The remaining shares are turned into an explicit instruction telling the model which source to trust when they disagree. They are priorities and an on/off switch, not a calibrated attention dial.', 'image-size-manager' ); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Extra instructions', 'image-size-manager' ); ?></th>
							<td>
								<textarea name="ism_ai_extra_prompt" id="ism-ai-extra-prompt" rows="4" class="large-text"
									placeholder="<?php esc_attr_e( 'e.g. This is a metal roofing supplier. Prefer product names like Standing Seam, Board &amp; Batten, 5-V Crimp. Never guess a colour name you cannot clearly see.', 'image-size-manager' ); ?>"><?php echo esc_textarea( ism_ai_get_extra_prompt() ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Added to every request, after the page context. Good for house vocabulary, product naming, or things the model keeps getting wrong. Leave blank to send nothing.', 'image-size-manager' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Source weighting', 'image-size-manager' ); ?></th>
							<td>
								<table class="ism-weights">
									<?php foreach ( $ism_weights as $ism_wk => $ism_wv ) : ?>
									<tr>
										<td class="ism-weight-label">
											<label for="ism-weight-<?php echo esc_attr( $ism_wk ); ?>"><?php echo esc_html( $ism_labels[ $ism_wk ] ); ?></label>
											<span class="description"><?php echo esc_html( $ism_hints[ $ism_wk ] ); ?></span>
										</td>
										<td class="ism-weight-input">
											<input type="range" class="ism-weight-range" data-weight="<?php echo esc_attr( $ism_wk ); ?>"
												min="0" max="100" step="5" value="<?php echo esc_attr( (string) $ism_wv ); ?>" />
											<input type="number" class="small-text ism-weight-number"
												id="ism-weight-<?php echo esc_attr( $ism_wk ); ?>"
												name="ism_ai_weights[<?php echo esc_attr( $ism_wk ); ?>]"
												data-weight="<?php echo esc_attr( $ism_wk ); ?>"
												min="0" max="100" value="<?php echo esc_attr( (string) $ism_wv ); ?>" />%
										</td>
									</tr>
									<?php endforeach; ?>
									<tr class="ism-weight-total-row">
										<td class="ism-weight-label"><strong><?php esc_html_e( 'Total', 'image-size-manager' ); ?></strong></td>
										<td class="ism-weight-input">
											<strong class="ism-weight-total">100</strong>%
											<span class="ism-weight-error" role="alert"></span>
										</td>
									</tr>
								</table>
								<p>
									<button type="button" class="button" id="ism-advanced-reset"><?php esc_html_e( 'Reset to defaults', 'image-size-manager' ); ?></button>
									<button type="button" class="button" id="ism-advanced-save"><?php esc_html_e( 'Save as default', 'image-size-manager' ); ?></button>
									<span class="ism-advanced-save-status description"></span>
								</p>
								<p class="description">
									<?php esc_html_e( 'Changes apply to the next Generate immediately. Saving only decides what these boxes start at next visit.', 'image-size-manager' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Usage index ─────────────────────────────────────────────── -->
			<div class="ism-card ism-bulk-card" style="margin-top:18px">
				<h4><?php esc_html_e( 'Step 1 — Build the usage index', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Maps every image to the pages, templates and custom fields that reference it. Generation uses that page text as context, so this must run first. No API key needed.', 'image-size-manager' ); ?>
				</p>
				<p class="description ism-index-state">
					<?php if ( $ism_index_built ) : ?>
						<span style="color:#1d7e2d;font-weight:500">
						<?php
						printf(
							/* translators: 1: post count, 2: attachment count, 3: human-readable time difference */
							esc_html__( '✓ Indexed %1$d posts, %2$d images — built %3$s ago', 'image-size-manager' ),
							(int) $ism_index_status['posts_indexed'],
							(int) $ism_index_status['attachments_found'],
							esc_html( human_time_diff( (int) $ism_index_status['built_at'] ) )
						);
						?>
						</span>
					<?php else : ?>
						<span style="color:#996800;font-weight:500"><?php esc_html_e( 'Not built yet.', 'image-size-manager' ); ?></span>
					<?php endif; ?>
				</p>
				<div class="ism-regen-controls">
					<button type="button" class="button" id="ism-usage-index-start">
						<?php echo $ism_index_built
							? esc_html__( 'Rebuild index', 'image-size-manager' )
							: esc_html__( 'Build index', 'image-size-manager' ); ?>
					</button>
				</div>
				<div class="ism-progress-wrap" id="ism-usage-index-progress" style="display:none">
					<div class="ism-progress-bar-track"><div class="ism-progress-bar-fill" id="ism-usage-index-bar"></div></div>
					<p class="ism-progress-status" id="ism-usage-index-status"></p>
				</div>
			</div>

			<!-- Scan ────────────────────────────────────────────────────── -->
			<div class="ism-card ism-bulk-card" style="margin-top:18px">
				<h4><?php esc_html_e( 'Step 2 — Scan the library', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Sorts every image into what can be generated for, what should be reviewed by hand, and what appears on no page. Read-only; no API key needed.', 'image-size-manager' ); ?>
				</p>
				<p class="description ism-seo-scan-state"></p>
				<div class="ism-regen-controls">
					<button type="button" class="button button-primary" id="ism-seo-scan-start">
						<?php esc_html_e( 'Load chart', 'image-size-manager' ); ?>
					</button>
					<button type="button" class="button" id="ism-seo-rescan">
						<?php esc_html_e( 'Rescan library', 'image-size-manager' ); ?>
					</button>
				</div>
				<div class="ism-progress-wrap" id="ism-seo-scan-progress" style="display:none">
					<div class="ism-progress-bar-track"><div class="ism-progress-bar-fill" id="ism-seo-scan-bar"></div></div>
					<p class="ism-progress-status" id="ism-seo-scan-status"></p>
				</div>
				<div id="ism-seo-summary" class="ism-seo-summary" style="display:none"></div>
			</div>

			<!-- The chart ───────────────────────────────────────────────── -->
			<div class="ism-card" id="ism-seo-chart-card" style="margin-top:18px; display:none">
				<h4><?php esc_html_e( 'Image usage chart', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Every image and every place it appears. Duplicate files are folded into one row — generate once and it applies to all copies. Tick the images you want proposals for, or use the bulk control above the table.', 'image-size-manager' ); ?>
				</p>

				<div class="ism-seo-chart-toolbar">
<label>
						<?php esc_html_e( 'Images', 'image-size-manager' ); ?>
						<select id="ism-seo-filter">
							<option value="usable"><?php esc_html_e( 'On the site (excluding decorative)', 'image-size-manager' ); ?></option>
							<option value="skipped"><?php esc_html_e( 'Decorative / unsupported', 'image-size-manager' ); ?></option>
							<option value="unused"><?php esc_html_e( 'Not found on any page', 'image-size-manager' ); ?></option>
							<option value="all"><?php esc_html_e( 'All images', 'image-size-manager' ); ?></option>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Review', 'image-size-manager' ); ?>
						<select id="ism-seo-review-filter">
							<option value="unreviewed"><?php esc_html_e( 'Unreviewed', 'image-size-manager' ); ?></option>
							<option value="reviewed"><?php esc_html_e( 'Reviewed', 'image-size-manager' ); ?></option>
							<option value="all"><?php esc_html_e( 'All', 'image-size-manager' ); ?></option>
						</select>
					</label>
					<label>
						<?php esc_html_e( 'Proposals', 'image-size-manager' ); ?>
						<select id="ism-seo-proposal-filter">
							<option value="all"><?php esc_html_e( 'All', 'image-size-manager' ); ?></option>
							<option value="proposals"><?php esc_html_e( 'Current proposals only', 'image-size-manager' ); ?></option>
						</select>
					</label>
					<input type="search" id="ism-seo-search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter by filename or page…', 'image-size-manager' ); ?>" />
					<button type="button" class="button" id="ism-seo-check-all" data-mode="select"><?php esc_html_e( 'Select all', 'image-size-manager' ); ?></button>
					<span class="ism-seo-chart-count"></span>
				</div>

				<div class="ism-seo-pager ism-seo-pager-top"></div>
				<div id="ism-seo-chart"></div>
				<div class="ism-seo-pager ism-seo-pager-bottom"></div>
			</div>

			<!-- Duplicate review (read-only) ────────────────────────────── -->
			<div class="ism-card" id="ism-seo-dupe-card" style="margin-top:18px; display:none">
				<h4><?php esc_html_e( 'Duplicate review', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Attachments that are byte-identical copies of each other. This panel is read-only — nothing here deletes, trashes, merges or repoints anything. It exists so you can see what each copy is and what still points at it before deciding anything.', 'image-size-manager' ); ?>
				</p>

				<p>
					<button type="button" class="button" id="ism-dupe-toggle" aria-expanded="false">
						<?php esc_html_e( 'Show duplicate review', 'image-size-manager' ); ?>
						<span class="ism-advanced-caret">▸</span>
					</button>
					<span class="ism-dupe-status description"></span>
				</p>

				<div id="ism-dupe-panel" hidden>
					<div class="ism-dupe-summary"></div>
					<div id="ism-dupe-list"></div>
					<p class="ism-dupe-note">
						<?php esc_html_e( 'To remove a redundant copy, use Trash from its own edit screen after confirming nothing above still needs it.', 'image-size-manager' ); ?>
					</p>
				</div>
			</div>

			<!-- Generate ────────────────────────────────────────────────── -->
			<div class="ism-card ism-bulk-card" id="ism-seo-generate-card" style="margin-top:18px; display:none">
				<h4><?php esc_html_e( 'Step 3 — Generate proposals', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Runs only over the generatable group. Nothing is written to the media library — every result lands in the review table below for you to edit and approve.', 'image-size-manager' ); ?>
				</p>

				<?php if ( ! $ism_has_key ) : ?>
					<p class="description" style="color:#996800;font-weight:500">
						<?php esc_html_e( 'Add an API key above and save to enable generation.', 'image-size-manager' ); ?>
					</p>
				<?php endif; ?>

				<p>
					<label style="display:block;margin-bottom:6px">
						<input type="radio" name="ism_seo_mode" value="selected" checked />
						<?php esc_html_e( 'Only the images I ticked in the chart', 'image-size-manager' ); ?>
						<span class="ism-seo-selected-count description"></span>
					</label>
					<label style="display:block">
						<input type="radio" name="ism_seo_mode" value="first" />
						<?php esc_html_e( 'The first', 'image-size-manager' ); ?>
						<input type="number" id="ism-seo-limit" class="small-text" value="20" min="1" max="2000" />
						<?php esc_html_e( 'ready images without a proposal yet', 'image-size-manager' ); ?>
					</label>
					<span class="description" style="display:block;margin-top:6px">
						<?php esc_html_e( 'Start small on a new site and read the output before scaling up.', 'image-size-manager' ); ?>
					</span>
				</p>

				<div class="ism-seo-estimate" id="ism-seo-estimate"></div>

				<div class="ism-regen-controls">
					<button type="button" class="button button-primary" id="ism-seo-generate-start" <?php disabled( ! $ism_has_key ); ?>>
						<?php esc_html_e( 'Generate', 'image-size-manager' ); ?>
					</button>
					<button type="button" class="button" id="ism-seo-generate-cancel" style="display:none">
						<?php esc_html_e( 'Stop', 'image-size-manager' ); ?>
					</button>
				</div>
				<div class="ism-progress-wrap" id="ism-seo-generate-progress" style="display:none">
					<div class="ism-progress-bar-track"><div class="ism-progress-bar-fill" id="ism-seo-generate-bar"></div></div>
					<p class="ism-progress-status" id="ism-seo-generate-status"></p>
					<ul class="ism-regen-log" id="ism-seo-generate-log" style="display:none"></ul>
				</div>
			</div>

			<!-- Review ──────────────────────────────────────────────────── -->
			<div class="ism-card" id="ism-seo-review-card" style="margin-top:18px; display:none">
				<h4><?php esc_html_e( 'Step 4 — Review and apply', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Every field is editable. Nothing is written until you press Apply, and only checked rows are written. An empty field is left unchanged rather than cleared.', 'image-size-manager' ); ?>
				</p>

				<div class="ism-seo-review-toolbar">
					<button type="button" class="button" id="ism-seo-select-all"><?php esc_html_e( 'Select all', 'image-size-manager' ); ?></button>
					<button type="button" class="button" id="ism-seo-select-none"><?php esc_html_e( 'Select none', 'image-size-manager' ); ?></button>
					<button type="button" class="button button-primary" id="ism-seo-apply"><?php esc_html_e( 'Apply selected', 'image-size-manager' ); ?></button>
					<button type="button" class="button" id="ism-seo-discard"><?php esc_html_e( 'Discard proposals', 'image-size-manager' ); ?></button>
					<span class="ism-seo-apply-status"></span>
				</div>

				<div id="ism-seo-review-list"></div>
			</div>


		</div><!-- /ism-panel-seo -->

		<!-- ═══════════════════════════════════════════════════════════════════
		     PANEL – BROKEN IMAGES
		     ══════════════════════════════════════════════════════════════════ -->
		<div id="ism-panel-broken" class="ism-panel" hidden>

			<div class="ism-card">
				<h2><?php esc_html_e( 'Broken Images', 'image-size-manager' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'References that point at an image which is no longer there. Two kinds: a reference to an attachment that has been deleted, and a reference to a file that is missing from disk. WordPress reports neither — the page just renders a gap.', 'image-size-manager' ); ?>
				</p>
				<p class="ism-broken-scope ism-broken-triage">
					<strong><?php esc_html_e( 'Not every broken reference is a broken image.', 'image-size-manager' ); ?></strong>
					<?php esc_html_e( 'Pages render the image URL, not the attachment ID, so a deleted attachment whose file is still on disk looks perfectly fine. References on saved templates may never render, and *_tablet or *_mobile variants only appear at those breakpoints. Each row is rated on that basis — and because the rating is a guess, every row has an "Is it actually broken?" button that loads the live page and tells you whether the file is really requested.', 'image-size-manager' ); ?>
				</p>
				<p class="ism-broken-scope">
					<?php esc_html_e( 'This tab finds broken references and suggests replacements. It does not repair anything yet — repointing has to rewrite Elementor JSON and ACF fields correctly, and that is being built and tested separately. Picking a replacement here records the decision so the repair step can use it later.', 'image-size-manager' ); ?>
				</p>
			</div>

			<div class="ism-card ism-bulk-card" style="margin-top:18px">
				<p class="description ism-broken-state"></p>
				<div class="ism-regen-controls">
					<button type="button" class="button button-primary" id="ism-broken-scan"><?php esc_html_e( 'Scan for broken images', 'image-size-manager' ); ?></button>
					<button type="button" class="button" id="ism-broken-rescan"><?php esc_html_e( 'Rescan', 'image-size-manager' ); ?></button>
				</div>
				<div class="ism-progress-wrap" id="ism-broken-progress" style="display:none">
					<div class="ism-progress-bar-track"><div class="ism-progress-bar-fill" id="ism-broken-bar"></div></div>
					<p class="ism-progress-status" id="ism-broken-status"></p>
				</div>
				<div class="ism-broken-summary" style="display:none"></div>
			</div>

			<div class="ism-card" id="ism-broken-list-card" style="margin-top:18px; display:none">
				<div class="ism-seo-chart-toolbar">
					<label>
						<?php esc_html_e( 'Show', 'image-size-manager' ); ?>
						<select id="ism-broken-filter">
							<option value="visible"><?php esc_html_e( 'Probably visible to visitors', 'image-size-manager' ); ?></option>
							<option value="harmless"><?php esc_html_e( 'Probably harmless leftovers', 'image-size-manager' ); ?></option>
							<option value="all"><?php esc_html_e( 'Everything', 'image-size-manager' ); ?></option>
							<option value="stale_id"><?php esc_html_e( 'Deleted attachment', 'image-size-manager' ); ?></option>
							<option value="missing_file"><?php esc_html_e( 'File missing from disk', 'image-size-manager' ); ?></option>
							<option value="recoverable"><?php esc_html_e( 'Filename recoverable', 'image-size-manager' ); ?></option>
							<option value="unrecoverable"><?php esc_html_e( 'No filename — needs manual pick', 'image-size-manager' ); ?></option>
							<option value="chosen"><?php esc_html_e( 'Replacement chosen', 'image-size-manager' ); ?></option>
						</select>
					</label>
					<input type="search" id="ism-broken-search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter by filename, page or field…', 'image-size-manager' ); ?>" />
					<span class="ism-broken-count"></span>
				</div>

				<div class="ism-seo-pager ism-broken-pager-top"></div>
				<div id="ism-broken-list"></div>
				<div class="ism-seo-pager ism-broken-pager-bottom"></div>
			</div>

		</div><!-- /ism-panel-broken -->

		<!-- ═══════════════════════════════════════════════════════════════════
		     PANEL 5 – ADVANCED (BULK TOOLS)
		     ══════════════════════════════════════════════════════════════════ -->
		<div id="ism-panel-advanced" class="ism-panel" hidden>
			<h2><?php esc_html_e( 'Advanced', 'image-size-manager' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'One-time cleanup tools for existing sites. On a properly configured new site these should rarely be needed.', 'image-size-manager' ); ?>
			</p>

			<h3 style="margin: 24px 0 6px"><?php esc_html_e( 'Bulk Image Tools', 'image-size-manager' ); ?></h3>
			<p class="description" style="margin-bottom:18px">
				<?php esc_html_e( 'Apply the Max Upload Dimensions setting to your existing library, or clean up -scaled files WordPress created for images larger than 2560px. Both operations run in small batches to avoid timeouts.', 'image-size-manager' ); ?>
			</p>

			<?php
			$max_w = (int) ( $settings['max_upload_width']  ?? 0 );
			$max_h = (int) ( $settings['max_upload_height'] ?? 0 );
			$has_limits = $max_w > 0 || $max_h > 0;
			?>

			<!-- Bulk resize ─────────────────────────────────────────────── -->
			<div class="ism-card ism-bulk-card">
				<h4><?php esc_html_e( 'Resize Existing Images to Max Upload Dimensions', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php
					if ( $has_limits ) {
						printf(
							esc_html__( 'Current limits: %s. Any image larger than this will be resized in-place and its metadata updated.', 'image-size-manager' ),
							esc_html(
								( $max_w > 0 ? $max_w . 'px wide' : '' )
								. ( $max_w > 0 && $max_h > 0 ? ' × ' : '' )
								. ( $max_h > 0 ? $max_h . 'px tall' : '' )
							)
						);
					} else {
						esc_html_e( 'No max upload dimensions are set. Configure them in the Registered Sizes tab, save, then return here.', 'image-size-manager' );
					}
					?>
				</p>
				<div class="ism-regen-controls">
					<button type="button" class="button button-primary" id="ism-bulk-resize-start"
						<?php disabled( ! $has_limits ); ?>>
						<?php esc_html_e( 'Resize All Existing Images', 'image-size-manager' ); ?>
					</button>
					<button type="button" class="button" id="ism-bulk-resize-cancel" style="display:none">
						<?php esc_html_e( 'Cancel', 'image-size-manager' ); ?>
					</button>
				</div>
				<div class="ism-progress-wrap" id="ism-bulk-resize-progress" style="display:none">
					<div class="ism-progress-bar-track"><div class="ism-progress-bar-fill" id="ism-bulk-resize-bar"></div></div>
					<p class="ism-progress-status" id="ism-bulk-resize-status"></p>
					<ul class="ism-regen-log" id="ism-bulk-resize-log" style="display:none"></ul>
				</div>
			</div>

			<?php
			$scaling_suppressed = $max_w > 0 || $max_h > 0;
			$scaling_limit      = $scaling_suppressed ? max( $max_w, $max_h ) : 0;
			$below_threshold    = $scaling_suppressed && $scaling_limit < 2560;
			$above_threshold    = $scaling_suppressed && $scaling_limit >= 2560;
			?>

			<!-- Remove -scaled ──────────────────────────────────────────── -->
			<div class="ism-card ism-bulk-card" style="margin-top:18px">
				<h4><?php esc_html_e( 'Remove WordPress -scaled Images', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'WordPress automatically creates a -scaled version of any image larger than 2560px. This tool deletes those -scaled files from disk, repoints the media library to the original file, and rewrites any page, template or custom field that already pointed at the -scaled version so nothing is left referencing a deleted file.', 'image-size-manager' ); ?>
				</p>

				<?php if ( $below_threshold ) : ?>
				<p class="description" style="color:#1d7e2d; margin-top:6px; font-weight:500">
					<?php
					printf(
						esc_html__( '✓ WordPress -scaled images are disabled. Your Max Upload size (%dpx) is below WordPress\'s 2560px threshold, so no new -scaled files will be created. Existing pages that already reference a -scaled file are rewritten to follow it.', 'image-size-manager' ),
						esc_html( $scaling_limit )
					);
					?>
				</p>
				<?php elseif ( $above_threshold ) : ?>
				<p class="description" style="color:#996800; margin-top:6px; font-weight:500">
					<?php
					printf(
						esc_html__( '⚠ WordPress -scaled images are being generated because your Max Upload size (%dpx) is greater than 2560px. Lower your Max Upload Width or Height below 2560px to disable -scaled files before running this tool.', 'image-size-manager' ),
						esc_html( $scaling_limit )
					);
					?>
				</p>
				<?php else : ?>
				<p class="description" style="color:#b32d2e; margin-top:6px">
					<?php esc_html_e( '⚠ WordPress -scaled images are currently being generated because no Max Upload size is configured (WordPress default is 2560px). Set a Max Upload Width or Height in the Registered Sizes tab and save first to disable -scaled before running this tool.', 'image-size-manager' ); ?>
				</p>
				<?php endif; ?>

				<div class="ism-regen-controls">
					<button type="button" class="button button-primary" id="ism-descale-start"
						<?php disabled( ! $below_threshold ); ?>>
						<?php esc_html_e( 'Find & Remove -scaled Images', 'image-size-manager' ); ?>
					</button>
					<button type="button" class="button" id="ism-descale-cancel" style="display:none">
						<?php esc_html_e( 'Cancel', 'image-size-manager' ); ?>
					</button>
				</div>
				<div class="ism-progress-wrap" id="ism-descale-progress" style="display:none">
					<div class="ism-progress-bar-track"><div class="ism-progress-bar-fill" id="ism-descale-bar"></div></div>
					<p class="ism-progress-status" id="ism-descale-status"></p>
					<ul class="ism-regen-log" id="ism-descale-log" style="display:none"></ul>
				</div>
			</div>

			<!-- Regenerate All Images ───────────────────────────────────── -->
			<div class="ism-card ism-bulk-card" style="margin-top:18px">
				<h4><?php esc_html_e( 'Regenerate All Thumbnails', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Loops through every image in the media library, regenerates all currently enabled size variations using your global size settings, and deletes any old size files that are no longer needed. Run this after changing size settings to apply them to existing images.', 'image-size-manager' ); ?>
				</p>
				<p class="ism-warning">
					⚠️ <?php esc_html_e( 'Old image size files will be permanently deleted from the server. This cannot be undone.', 'image-size-manager' ); ?>
				</p>
				<div class="ism-regen-controls">
					<button type="button" class="button button-primary" id="ism-regen-all-start">
						<?php esc_html_e( 'Regenerate All Thumbnails', 'image-size-manager' ); ?>
					</button>
					<button type="button" class="button" id="ism-regen-all-cancel" style="display:none">
						<?php esc_html_e( 'Cancel', 'image-size-manager' ); ?>
					</button>
				</div>
				<div class="ism-progress-wrap" id="ism-regen-all-progress" style="display:none">
					<div class="ism-progress-bar-track"><div class="ism-progress-bar-fill" id="ism-regen-all-bar"></div></div>
					<p class="ism-progress-status" id="ism-regen-all-status"></p>
					<ul class="ism-regen-log" id="ism-regen-all-log" style="display:none"></ul>
				</div>
			</div>

			<!-- Image size usage scanner ─────────────────────────────────── -->
			<div class="ism-card ism-bulk-card" style="margin-top:18px" id="ism-size-scan-card">
				<h4><?php esc_html_e( 'Scan Image Size Usage', 'image-size-manager' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Scans all content in this site to find which registered image sizes are actually used. Use the results to identify sizes that can safely be disabled to stop generating unnecessary files.', 'image-size-manager' ); ?>
				</p>

				<!-- What gets scanned -->
				<ul class="ism-scan-sources">
					<li><strong><?php esc_html_e( 'Gutenberg / Block Editor', 'image-size-manager' ); ?></strong> — <?php esc_html_e( 'detects the selected size in image blocks and gallery blocks across all published, draft, and private posts.', 'image-size-manager' ); ?></li>
					<li><strong><?php esc_html_e( 'Classic Editor', 'image-size-manager' ); ?></strong> — <?php esc_html_e( 'detects size CSS classes (size-large, attachment-medium, etc.) added by WordPress when an image is inserted.', 'image-size-manager' ); ?></li>
					<li><strong><?php esc_html_e( 'Gallery Shortcode', 'image-size-manager' ); ?></strong> — <?php esc_html_e( 'reads the size="…" attribute of [gallery] shortcodes.', 'image-size-manager' ); ?></li>
					<li><strong><?php esc_html_e( 'Elementor', 'image-size-manager' ); ?></strong> — <?php esc_html_e( 'reads the _elementor_data JSON stored for each page, covering Image widgets, Background Image, Logo, and any widget with an image size control.', 'image-size-manager' ); ?></li>
				</ul>

				<p class="description ism-scan-caveat">
					<strong><?php esc_html_e( 'Note on srcset (responsive images):', 'image-size-manager' ); ?></strong>
					<?php esc_html_e( 'WordPress automatically builds a srcset from all available image sizes and lets the browser pick the best fit for each viewport. A size that shows as "Unused" in content may still be served to some device widths. If you disable an unused size, the browser will fall back to the next larger available size — this is usually fine if the gap in width between sizes is not too large.', 'image-size-manager' ); ?>
				</p>
				<p class="description ism-scan-caveat">
					<strong><?php esc_html_e( 'Note on theme and plugin templates:', 'image-size-manager' ); ?></strong>
					<?php esc_html_e( 'This scanner only reads database content. Sizes referenced directly in PHP templates (e.g. the_post_thumbnail("hero") in a theme file, or WooCommerce product image templates) will not be detected. WooCommerce sizes are automatically marked as "Plugin" to prevent accidental disabling.', 'image-size-manager' ); ?>
				</p>

				<div class="ism-regen-controls" style="margin-top:14px">
					<button type="button" class="button button-primary" id="ism-size-scan-start">
						<?php esc_html_e( 'Scan Now', 'image-size-manager' ); ?>
					</button>
				</div>

				<div id="ism-size-scan-results" style="display:none; margin-top:18px">
					<p class="ism-scan-summary" id="ism-scan-summary"></p>
					<table class="widefat ism-scan-results-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Size', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Dimensions', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Status', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Content', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Elementor', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Total Refs', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Files on Disk', 'image-size-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="ism-scan-tbody">
							<!-- Populated by JS -->
						</tbody>
					</table>
					<p class="description" style="margin-top:10px">
						<?php esc_html_e( 'To disable an unused size, go to the Registered Sizes tab and toggle it off, then save.', 'image-size-manager' ); ?>
					</p>
				</div>
			</div>

		</div><!-- /ism-panel-advanced -->

		<!-- ── Submit ────────────────────────────────────────────────────── -->
		<p class="ism-submit-row">
			<?php submit_button( __( 'Save Settings', 'image-size-manager' ), 'primary large', 'submit', false ); ?>
		</p>

	</form>
</div><!-- /.ism-wrap -->
