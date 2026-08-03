<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap gbp-sync-wrap">
	<h1>Location Sync — SerpAPI</h1>

	<div class="gbp-sync-status-bar">
		<span class="gbp-status <?php echo $connected ? 'connected' : 'disconnected'; ?>">
			<?php echo $connected ? '● SerpAPI Connected' : '● SerpAPI Not Configured'; ?>
		</span>
		<span class="gbp-status <?php echo $hours_ready ? 'connected' : 'disconnected'; ?>">
			<?php echo $hours_ready ? '● Places API Connected' : '● Places API Not Configured'; ?>
		</span>
		<span class="gbp-last-run">Last full sync: <strong><?php echo esc_html( $last_run ); ?></strong></span>
		<span class="gbp-last-run">Last hours sync: <strong><?php echo esc_html( $hours_run ); ?></strong></span>
	</div>

	<div class="gbp-sync-tabs">
		<ul class="gbp-tab-nav">
			<li><a href="#tab-settings" class="active">Settings</a></li>
			<li><a href="#tab-locations">Locations</a></li>
			<li><a href="#tab-import">Import</a></li>
		</ul>

		<!-- ── Tab: Settings ── -->
		<div id="tab-settings" class="gbp-tab active">
			<h2>SerpAPI Settings</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'gbp_sync_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="gbp_sync_serpapi_key">SerpAPI Key</label></th>
						<td>
							<input type="password" id="gbp_sync_serpapi_key" name="gbp_sync_serpapi_key"
								value="<?php echo esc_attr( get_option( 'gbp_sync_serpapi_key', '' ) ); ?>"
								class="regular-text" autocomplete="off">
							<p class="description">
								<a href="https://serpapi.com/manage-api-key" target="_blank">serpapi.com/manage-api-key</a>
								— used by <strong>Full Sync</strong> only, 1 credit per location. Hours come from the Places API.
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="gbp_sync_maps_embed_key">Maps Embed API Key <span style="font-weight:normal;color:#666">(optional)</span></label></th>
						<td>
							<input type="password" id="gbp_sync_maps_embed_key" name="gbp_sync_maps_embed_key"
								value="<?php echo esc_attr( get_option( 'gbp_sync_maps_embed_key', '' ) ); ?>"
								class="regular-text" autocomplete="off">
							<p class="description">
								Enables <code>embed/v1/place?q=place_id:…</code> iframes. Leave blank to use keyless lat/lng fallback.
								In Google Cloud Console → enable "Maps Embed API" on the same project.
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="gbp_sync_places_api_key">Places API Key <span style="font-weight:normal;color:#666">(primary hours source)</span></label></th>
						<td>
							<input type="password" id="gbp_sync_places_api_key" name="gbp_sync_places_api_key"
								value="<?php echo esc_attr( get_option( 'gbp_sync_places_api_key', '' ) ); ?>"
								class="regular-text" autocomplete="off">
							<p class="description">
								<strong>Required for hours.</strong> Hours, holiday overrides and open/closed status all read from <strong>Places API (New)</strong>.
								Google Cloud Console &rarr; enable "Places API (New)" + billing on the same project. Leave blank to reuse the Maps Embed key above.
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="gbp_sync_brand_prefix">Brand Name Prefix</label></th>
						<td>
							<input type="text" id="gbp_sync_brand_prefix" name="gbp_sync_brand_prefix"
								value="<?php echo esc_attr( get_option( 'gbp_sync_brand_prefix', '' ) ); ?>"
								class="regular-text" placeholder="e.g. Emergency Dental of ">
							<p class="description">
								Stripped from the GBP name to set the CPT post title and slug.
								"Emergency Dental of Milwaukee" → title: <strong>Milwaukee</strong>, slug: <code>milwaukee</code>.
								Leave blank to use the full GBP name.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<hr>
			<h3>Reviews</h3>
			<p>Reviews managed separately via Airtable. This plugin syncs <strong>rating score</strong> and <strong>review count</strong> only. Full review content lives in Airtable.</p>
		</div>

		<!-- ── Tab: Locations ── -->
		<div id="tab-locations" class="gbp-tab">
			<h2>Locations</h2>

			<div class="gbp-sync-actions">
				<button id="gbp-sync-hours-btn" class="button button-primary" <?php echo ! $hours_ready ? 'disabled' : ''; ?>>
					Sync Hours (All)
				</button>
				<button id="gbp-sync-all-btn" class="button" <?php echo ! $connected ? 'disabled' : ''; ?>>
					Full Sync (All)
				</button>
				<span id="gbp-sync-spinner" class="spinner"></span>
				<div id="gbp-sync-result"></div>
			</div>

			<p class="description" style="margin-bottom:12px">
				<strong>Sync Hours</strong> reads hours, holiday overrides and open/closed status from the Places API — no SerpAPI credits.
				<strong>Full Sync</strong> additionally refreshes name, address, phone, rating and review count via SerpAPI.
				Hand-edited hours are kept until Google's own hours change.
			</p>

			<p class="description" style="margin-bottom:12px">
				Only locations with a <strong>Google Place ID</strong> set will sync.
				Edit each location post → Profile tab → Google Place ID field.
			</p>

			<table class="wp-list-table widefat fixed striped gbp-locations-table">
				<thead>
					<tr>
						<th>Location</th>
						<th>Place ID</th>
						<th>Status</th>
						<th>Hours</th>
						<th>Rating</th>
						<th>Last Synced</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$locations = get_posts( [
					'post_type'      => GBP_SYNC_POST_TYPE,
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				] );

				if ( empty( $locations ) ) :
				?>
					<tr><td colspan="7">No location posts found.</td></tr>
				<?php else : ?>
					<?php foreach ( $locations as $loc_post ) :
						$place_id    = get_field( 'loc_place_id',    $loc_post->ID );
						$temp_closed = get_field( 'loc_temp_closed',  $loc_post->ID );
						$status      = get_field( 'loc_status',       $loc_post->ID );
						$rating      = get_field( 'loc_rating',       $loc_post->ID );
						$last_synced = get_field( 'gbp_last_synced',  $loc_post->ID );
						$hours_state = GBP_Hours_Sync::staleness( $loc_post->ID );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $loc_post->ID ) ); ?>">
								<?php echo esc_html( $loc_post->post_title ); ?>
							</a>
						</td>
						<td>
							<?php if ( $place_id ) : ?>
								<code><?php echo esc_html( substr( $place_id, 0, 22 ) . '…' ); ?></code>
							<?php else : ?>
								<span style="color:#d63638">⚠ Not set</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $temp_closed ) : ?>
								<span class="gbp-badge gbp-badge-warning">Temp Closed</span>
							<?php elseif ( $status === 'CLOSED_PERMANENTLY' ) : ?>
								<span class="gbp-badge gbp-badge-error">Perm Closed</span>
							<?php else : ?>
								<span class="gbp-badge gbp-badge-success">Open</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $hours_state['stale'] ) : ?>
								<span style="color:#d63638">⚠ <?php echo esc_html( $hours_state['label'] ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $hours_state['label'] ); ?>
							<?php endif; ?>
							<?php if ( $hours_state['source'] ) : ?>
								<br><span class="description"><?php echo esc_html( $hours_state['source'] ); ?></span>
							<?php endif; ?>
							<?php if ( $hours_state['error'] ) : ?>
								<br><span class="description" style="color:#d63638" title="<?php echo esc_attr( $hours_state['error'] ); ?>">sync error</span>
							<?php endif; ?>
						</td>
						<td><?php echo $rating ? '★ ' . esc_html( $rating ) : '—'; ?></td>
						<td><?php echo esc_html( $last_synced ?: 'Never' ); ?></td>
						<td>
							<?php if ( $place_id ) : ?>
								<button class="button gbp-sync-one-btn"
									data-post-id="<?php echo esc_attr( $loc_post->ID ); ?>">
									Sync Now
								</button>
							<?php else : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $loc_post->ID ) ); ?>" class="button">
									Add Place ID
								</a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<!-- ── Tab: Import ── -->
		<div id="tab-import" class="gbp-tab">
			<h2>Import Locations</h2>
			<p class="description" style="margin-bottom:16px">
				Searches SerpAPI for <strong>Emergency Dental of America</strong> and shows locations not yet in WordPress.
				Each import creates a new location post and syncs it immediately.
			</p>

			<div class="gbp-sync-actions">
				<button id="gbp-search-btn" class="button button-primary" <?php echo ! $connected ? 'disabled' : ''; ?>>
					Search for Missing Locations
				</button>
				<span id="gbp-search-spinner" class="spinner"></span>
			</div>

			<div id="gbp-import-results" style="margin-top:16px"></div>
		</div>
	</div>
</div>
