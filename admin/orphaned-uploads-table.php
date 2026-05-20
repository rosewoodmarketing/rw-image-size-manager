<?php
/**
 * Orphaned Uploads Table for RW Image Size Manager
 *
 * This file renders a dashboard table listing all files in uploads,
 * highlighting those not referenced in the WordPress database.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function ism_render_orphaned_uploads_table() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$upload_dir = wp_upload_dir();
	$base_dir = trailingslashit( $upload_dir['basedir'] );
	$base_url = trailingslashit( $upload_dir['baseurl'] );

	// 1. Recursively list all files in uploads
	$all_files = [];
	$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir));
	foreach ($rii as $file) {
		if ($file->isDir()) continue;
		$rel = ltrim(str_replace($base_dir, '', $file->getPathname()), '/\\');
		$all_files[$rel] = $file->getPathname();
	}

	// 2. Collect all referenced files from DB
	global $wpdb;
	$referenced = [];
	// _wp_attached_file
	$rows = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'");
	foreach ($rows as $rel) {
		if ($rel) $referenced[$rel] = true;
	}
	// _wp_attachment_metadata
	$rows = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'");
	foreach ($rows as $raw) {
		$meta = @maybe_unserialize($raw);
		if (!is_array($meta)) continue;
		if (!empty($meta['file'])) $referenced[$meta['file']] = true;
		if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
			foreach ($meta['sizes'] as $size) {
				if (!empty($size['file'])) {
					$dir = dirname($meta['file']);
					$rel = ($dir && $dir !== '.') ? trailingslashit($dir) . $size['file'] : $size['file'];
					$referenced[$rel] = true;
				}
			}
		}
		if (!empty($meta['original_image'])) {
			$dir = dirname($meta['file']);
			$rel = ($dir && $dir !== '.') ? trailingslashit($dir) . $meta['original_image'] : $meta['original_image'];
			$referenced[$rel] = true;
		}
	}

	// 3. Render table with search and sortable columns
	// Collect file types
	$file_types = [];
	$file_type_map = [];
	foreach ($all_files as $rel => $abs) {
		$ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
		if (!$ext) $ext = 'none';
		$file_types[$ext] = true;
		$file_type_map[$rel] = $ext;
	}
	ksort($file_types);

	?>
	<div class="wrap">
		<h1>Uploads Audit</h1>
		<div style="margin-bottom:10px;display:flex;gap:10px;align-items:center;">
			<input type="text" id="ism-upload-search" placeholder="Search files..." style="width:300px;" />
			<select id="ism-filetype-filter" multiple size="1" style="min-width:120px;max-width:200px;" title="Filter by file type">
				<?php foreach ($file_types as $ext => $_): ?>
					<option value="<?php echo esc_attr($ext); ?>"><?php echo esc_html(strtoupper($ext)); ?></option>
				<?php endforeach; ?>
			</select>
			<span style="font-size:12px;color:#666;">(Hold Ctrl/Cmd to multi-select)</span>
		</div>
		<table id="ism-orphaned-table" class="widefat fixed striped">
			<thead>
				<tr>
					<th class="ism-sortable" data-sort="file" style="cursor:pointer">File <span class="dashicons dashicons-sort"></span></th>
					<th class="ism-sortable" data-sort="type" style="cursor:pointer">File Type <span class="dashicons dashicons-sort"></span></th>
					<th class="ism-sortable" data-sort="status" style="cursor:pointer">Status <span class="dashicons dashicons-sort"></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($all_files as $rel => $abs) :
					$is_orphan = !isset($referenced[$rel]);
					$status_text = $is_orphan ? 'Orphaned' : 'OK';
					$status_style = $is_orphan ? 'color:red;font-weight:bold' : '';
					$ext = esc_html(strtolower(pathinfo($rel, PATHINFO_EXTENSION)) ?: 'none');
				?>
				<tr data-filetype="<?php echo $ext; ?>">
					<td><a href="<?php echo esc_url($base_url . $rel); ?>" target="_blank"><?php echo esc_html($rel); ?></a></td>
					<td><?php echo strtoupper($ext); ?></td>
					<td><span<?php echo $status_style ? ' style="' . esc_attr($status_style) . '"' : ''; ?>><?php echo esc_html($status_text); ?></span></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<style>
			#ism-orphaned-table th.ism-sortable { user-select: none; }
			#ism-filetype-filter { vertical-align: middle; }
		</style>
		<script>
		(function(){
			// Sorting
			function sortTable(table, col, type, asc) {
				const tbody = table.tBodies[0];
				const rows = Array.from(tbody.querySelectorAll('tr'));
				rows.sort(function(a, b) {
					let aText = a.children[col].textContent.trim().toLowerCase();
					let bText = b.children[col].textContent.trim().toLowerCase();
					if(type === 'status') {
						aText = aText === 'ok' ? '1' : '0';
						bText = bText === 'ok' ? '1' : '0';
					}
					if(aText < bText) return asc ? -1 : 1;
					if(aText > bText) return asc ? 1 : -1;
					return 0;
				});
				rows.forEach(row => tbody.appendChild(row));
			}
			const table = document.getElementById('ism-orphaned-table');
			let sortState = { col: 0, asc: true };
			table.querySelectorAll('th.ism-sortable').forEach(function(th, idx) {
				th.addEventListener('click', function() {
					const type = th.dataset.sort;
					const asc = sortState.col === idx ? !sortState.asc : true;
					sortTable(table, idx, type, asc);
					sortState = { col: idx, asc };
				});
			});
			// Search and filetype filter
			const searchInput = document.getElementById('ism-upload-search');
			const filetypeFilter = document.getElementById('ism-filetype-filter');
			function filterRows() {
				const val = searchInput.value.trim().toLowerCase();
				const selectedTypes = Array.from(filetypeFilter.selectedOptions).map(o => o.value);
				Array.from(table.tBodies[0].rows).forEach(function(row) {
					const file = row.cells[0].textContent.toLowerCase();
					const type = row.getAttribute('data-filetype');
					const matchesType = !selectedTypes.length || selectedTypes.includes(type);
					const matchesSearch = file.indexOf(val) !== -1;
					row.style.display = matchesType && matchesSearch ? '' : 'none';
				});
			}
			searchInput.addEventListener('input', filterRows);
			filetypeFilter.addEventListener('change', filterRows);
		})();
		</script>
	</div>
	<?php
}
