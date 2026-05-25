<?php
/**
 * Registers ACF field group against existing 'location' CPT.
 * Does NOT register the CPT — it already exists on this site.
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'GBP_SYNC_POST_TYPE' ) ) {
	define( 'GBP_SYNC_POST_TYPE', 'location' );
}

class GBP_Location_CPT {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks(): void {
		// CPT already registered — only register ACF fields.
		add_action( 'acf/init', [ $this, 'register_acf_fields' ] );
	}

	public function register_cpt(): void {
		// Kept for reference — not called. CPT 'location' exists site-side.
		register_post_type( 'location', [
			'labels' => [
				'name'               => 'Locations',
				'singular_name'      => 'Location',
				'add_new_item'       => 'Add New Location',
				'edit_item'          => 'Edit Location',
				'search_items'       => 'Search Locations',
				'not_found'          => 'No locations found.',
				'menu_name'          => 'Locations',
			],
			'public'             => true,
			'show_in_rest'       => true,
			'has_archive'        => true,
			'rewrite'            => [ 'slug' => 'locations' ],
			'supports'           => [ 'title', 'editor', 'thumbnail', 'revisions' ],
			'menu_icon'          => 'dashicons-location-alt',
			'menu_position'      => 20,
		] );
	}

	public function register_acf_fields(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group( [
			'key'      => 'group_gbp_location',
			'title'    => 'GBP Location Data',
			'fields'   => $this->get_field_definitions(),
			'location' => [
				[ [ 'param' => 'post_type', 'operator' => '==', 'value' => GBP_SYNC_POST_TYPE ] ],
			],
			'menu_order'            => 0,
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
		] );
	}

	private function get_field_definitions(): array {
		return [

			// ── Tab: Profile ──────────────────────────────────────────────────
			[
				'key'   => 'field_loc_tab_profile',
				'label' => 'Profile',
				'name'  => '',
				'type'  => 'tab',
			],
			[
				'key'   => 'field_loc_name',
				'label' => 'Business Name',
				'name'  => 'loc_name',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_phone',
				'label' => 'Phone',
				'name'  => 'loc_phone',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_phone_alt',
				'label' => 'Alt Phone',
				'name'  => 'loc_phone_alt',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_email',
				'label' => 'Email',
				'name'  => 'loc_email',
				'type'  => 'email',
			],
			[
				'key'   => 'field_loc_website',
				'label' => 'Website',
				'name'  => 'loc_website',
				'type'  => 'url',
			],
			[
				'key'          => 'field_loc_maps_url',
				'label'        => 'Google Maps URL',
				'name'         => 'loc_maps_url',
				'type'         => 'url',
				'instructions' => 'Public Google Maps link for this location.',
			],
			[
				'key'          => 'field_loc_maps_embed',
				'label'        => 'Map Embed',
				'name'         => 'loc_maps_embed',
				'type'         => 'textarea',
				'rows'         => 3,
				'instructions' => 'Auto-generated iframe by sync. Paste directly into page builder or template.',
				'readonly'     => 1,
			],
			[
				'key'          => 'field_loc_place_id',
				'label'        => 'Google Place ID',
				'name'         => 'loc_place_id',
				'type'         => 'text',
				'instructions' => 'Required for SerpAPI sync. Google Maps → location → Share → copy link → the ID after "place/" or starts with ChIJ.',
				'placeholder'  => 'ChIJ…',
			],

			// ── Tab: Address ──────────────────────────────────────────────────
			[
				'key'   => 'field_loc_tab_address',
				'label' => 'Address',
				'name'  => '',
				'type'  => 'tab',
			],
			[
				'key'   => 'field_loc_address_1',
				'label' => 'Street Address',
				'name'  => 'loc_address_1',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_address_2',
				'label' => 'Suite / Unit',
				'name'  => 'loc_address_2',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_city',
				'label' => 'City',
				'name'  => 'loc_city',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_state',
				'label' => 'State',
				'name'  => 'loc_state',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_zip',
				'label' => 'ZIP Code',
				'name'  => 'loc_zip',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_lat',
				'label' => 'Latitude',
				'name'  => 'loc_lat',
				'type'  => 'text',
			],
			[
				'key'   => 'field_loc_lng',
				'label' => 'Longitude',
				'name'  => 'loc_lng',
				'type'  => 'text',
			],

			// ── Tab: Hours & Status ───────────────────────────────────────────
			[
				'key'   => 'field_loc_tab_hours',
				'label' => 'Hours & Status',
				'name'  => '',
				'type'  => 'tab',
			],
			[
				'key'     => 'field_loc_status',
				'label'   => 'Status',
				'name'    => 'loc_status',
				'type'    => 'select',
				'choices' => [
					'OPEN'               => 'Open',
					'CLOSED_TEMPORARILY' => 'Temporarily Closed',
					'CLOSED_PERMANENTLY' => 'Permanently Closed',
				],
				'default_value' => 'OPEN',
			],
			[
				'key'          => 'field_loc_temp_closed',
				'label'        => 'Temporarily Closed',
				'name'         => 'loc_temp_closed',
				'type'         => 'true_false',
				'ui'           => 1,
				'instructions' => 'Auto-set by GBP sync. Controls closure banners site-wide.',
			],
			[
				'key'          => 'field_loc_closure_notice',
				'label'        => 'Closure Notice',
				'name'         => 'loc_closure_notice',
				'type'         => 'text',
				'instructions' => 'Short message shown on site during closure. e.g. "Closed due to weather — reopening Monday."',
			],
			[
				'key'          => 'field_loc_hours',
				'label'        => 'Regular Hours',
				'name'         => 'loc_hours',
				'type'         => 'repeater',
				'layout'       => 'table',
				'button_label' => 'Add Row',
				'sub_fields'   => [
					[
						'key'     => 'field_loc_hours_day',
						'label'   => 'Day',
						'name'    => 'day',
						'type'    => 'select',
						'choices' => [
							'MONDAY'    => 'Monday',
							'TUESDAY'   => 'Tuesday',
							'WEDNESDAY' => 'Wednesday',
							'THURSDAY'  => 'Thursday',
							'FRIDAY'    => 'Friday',
							'SATURDAY'  => 'Saturday',
							'SUNDAY'    => 'Sunday',
						],
					],
					[
						'key'   => 'field_loc_hours_open',
						'label' => 'Opens',
						'name'  => 'open_time',
						'type'  => 'text',
					],
					[
						'key'   => 'field_loc_hours_close',
						'label' => 'Closes',
						'name'  => 'close_time',
						'type'  => 'text',
					],
					[
						'key'   => 'field_loc_hours_closed',
						'label' => 'Closed',
						'name'  => 'is_closed',
						'type'  => 'true_false',
						'ui'    => 1,
					],
				],
			],
			[
				'key'          => 'field_loc_special_hours',
				'label'        => 'Special / Holiday Hours',
				'name'         => 'loc_special_hours',
				'type'         => 'repeater',
				'layout'       => 'table',
				'button_label' => 'Add Date',
				'instructions' => 'Holiday closures and emergency dates. Auto-populated from GBP.',
				'sub_fields'   => [
					[
						'key'   => 'field_loc_sp_date',
						'label' => 'Date',
						'name'  => 'date',
						'type'  => 'text',
					],
					[
						'key'   => 'field_loc_sp_closed',
						'label' => 'Closed',
						'name'  => 'is_closed',
						'type'  => 'true_false',
						'ui'    => 1,
					],
					[
						'key'   => 'field_loc_sp_open',
						'label' => 'Opens',
						'name'  => 'open_time',
						'type'  => 'text',
					],
					[
						'key'   => 'field_loc_sp_close',
						'label' => 'Closes',
						'name'  => 'close_time',
						'type'  => 'text',
					],
				],
			],

			// ── Tab: Reviews (score only — full reviews in Airtable) ─────────
			[
				'key'   => 'field_loc_tab_reviews',
				'label' => 'Reviews',
				'name'  => '',
				'type'  => 'tab',
			],
			[
				'key'          => 'field_loc_rating',
				'label'        => 'Average Rating',
				'name'         => 'loc_rating',
				'type'         => 'number',
				'step'         => 0.1,
				'min'          => 0,
				'max'          => 5,
				'instructions' => 'Auto-synced from Google Maps via SerpAPI.',
			],
			[
				'key'          => 'field_loc_review_count',
				'label'        => 'Total Reviews',
				'name'         => 'loc_review_count',
				'type'         => 'number',
				'instructions' => 'Auto-synced from Google Maps via SerpAPI.',
			],

			// ── Sync Meta ─────────────────────────────────────────────────────
			[
				'key'   => 'field_loc_tab_sync',
				'label' => 'Sync',
				'name'  => '',
				'type'  => 'tab',
			],
			[
				'key'            => 'field_gbp_last_synced',
				'label'          => 'Last Synced',
				'name'           => 'gbp_last_synced',
				'type'           => 'date_time_picker',
				'readonly'       => 1,
				'display_format' => 'F j, Y g:i a',
				'return_format'  => 'Y-m-d H:i:s',
				'instructions'   => 'Set automatically by SerpAPI sync.',
			],
		];
	}
}
