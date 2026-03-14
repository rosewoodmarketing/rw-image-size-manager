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
	<h1><?php esc_html_e( 'RW Image Size Manager', 'image-size-manager' ); ?></h1>

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
			<button type="button" class="ism-tab" data-target="ism-panel-orphans">
				<?php esc_html_e( 'Orphaned Files', 'image-size-manager' ); ?>
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
					<td><?php echo $size['crop'] ? '✓' : '—'; ?></td>
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
		     PANEL 5 – ORPHANED FILES
		     ══════════════════════════════════════════════════════════════════ -->
		<div id="ism-panel-orphans" class="ism-panel" hidden>
			<div class="ism-card">
				<h2><?php esc_html_e( 'Orphaned Files', 'image-size-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These image files exist in your uploads folder but have no matching entry in the WordPress media library. Scan first, then delete any you no longer need.', 'image-size-manager' ); ?></p>

				<div class="ism-orphan-toolbar">
					<button type="button" class="button button-primary" id="ism-orphan-scan-btn"><?php esc_html_e( 'Scan Uploads Folder', 'image-size-manager' ); ?></button>
					<span class="ism-orphan-status"></span>
				</div>

				<div id="ism-orphan-results" style="display:none">
					<div class="ism-orphan-summary"></div>

					<div class="ism-orphan-actions">
						<label><input type="checkbox" id="ism-orphan-select-all"> <?php esc_html_e( 'Select all', 'image-size-manager' ); ?></label>
						<button type="button" class="button button-link-delete" id="ism-orphan-delete-btn" disabled><?php esc_html_e( 'Delete selected', 'image-size-manager' ); ?></button>
					</div>

					<table class="widefat ism-orphan-table">
						<thead>
							<tr>
								<th style="width:32px"></th>
								<th><?php esc_html_e( 'File path', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Size', 'image-size-manager' ); ?></th>
								<th><?php esc_html_e( 'Modified', 'image-size-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="ism-orphan-tbody"></tbody>
					</table>
				</div>
			</div>
		</div><!-- /ism-panel-orphans -->

		<!-- ── Submit ────────────────────────────────────────────────────── -->
		<p class="ism-submit-row">
			<?php submit_button( __( 'Save Settings', 'image-size-manager' ), 'primary large', 'submit', false ); ?>
		</p>

	</form>
</div><!-- /.ism-wrap -->
