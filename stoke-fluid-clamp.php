<?php
/**
 * Plugin Name: Stoke Fluid Clamp
 * Description: Generates fluid clamp() CSS custom properties from a max px value. Set the viewport range once, add tokens, use the vars anywhere (Elementor, SCSS, raw CSS).
 * Version:     1.26.6.10
 * Author:      Stoke Design Co
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stoke_Fluid_Clamp {

	const OPTION = 'sfc_settings';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'wp_head', [ __CLASS__, 'output_css' ], 5 );
	}

	/* ---------- Defaults ---------- */

	public static function defaults() {
		return [
			'min_vw'    => 320,
			'max_vw'    => 1920,
			'min_ratio' => 0.5,
			'root_px'   => 16,
			'tokens'    => [
				// [ 'name' => 'fs-display', 'max' => 107, 'min' => '' ],
			],
		];
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, [] );
		return wp_parse_args( $saved, self::defaults() );
	}

	/* ---------- The maths ---------- */

	public static function build_clamp( $min_px, $max_px, $min_vw, $max_vw, $root_px = 16 ) {
		if ( $max_vw <= $min_vw || $max_px <= $min_px ) {
			return sprintf( '%srem', self::to_rem( $max_px, $root_px ) );
		}

		$slope     = ( $max_px - $min_px ) / ( $max_vw - $min_vw );
		$intercept = $min_px - ( $slope * $min_vw );

		return sprintf(
			'clamp(%srem, %srem + %svw, %srem)',
			self::to_rem( $min_px, $root_px ),
			self::to_rem( $intercept, $root_px ),
			self::trim_num( round( $slope * 100, 4 ) ),
			self::to_rem( $max_px, $root_px )
		);
	}

	private static function to_rem( $px, $root_px ) {
		return self::trim_num( round( $px / $root_px, 4 ) );
	}

	private static function trim_num( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 4, '.', '' ), '0' ), '.' );
	}

	/* ---------- Front-end output ---------- */

	public static function output_css() {
		$s = self::get_settings();

		if ( empty( $s['tokens'] ) ) {
			return;
		}

		$lines = [];

		foreach ( $s['tokens'] as $token ) {
			$name = sanitize_title( $token['name'] ?? '' );
			$max  = floatval( $token['max'] ?? 0 );

			if ( ! $name || $max <= 0 ) {
				continue;
			}

			$min = ( '' !== ( $token['min'] ?? '' ) && null !== ( $token['min'] ?? null ) )
				? floatval( $token['min'] )
				: round( $max * floatval( $s['min_ratio'] ), 2 );

			$lines[] = sprintf(
				'--%s: %s;',
				$name,
				self::build_clamp( $min, $max, intval( $s['min_vw'] ), intval( $s['max_vw'] ), floatval( $s['root_px'] ) )
			);
		}

		if ( ! $lines ) {
			return;
		}

		printf(
			"<style id=\"fluid-clamp-tokens\">:root{\n\t%s\n}</style>\n",
			implode( "\n\t", $lines ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	/* ---------- Admin ---------- */

	public static function admin_menu() {
		add_options_page(
			'Stoke Fluid Clamp',
			'Stoke Fluid Clamp',
			'manage_options',
			'stoke-fluid-clamp',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		register_setting( 'sfc_group', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );
	}

	public static function sanitize( $input ) {
		$clean = [
			'min_vw'    => max( 1, intval( $input['min_vw'] ?? 320 ) ),
			'max_vw'    => max( 2, intval( $input['max_vw'] ?? 1920 ) ),
			'min_ratio' => min( 1, max( 0.1, floatval( $input['min_ratio'] ?? 0.5 ) ) ),
			'root_px'   => max( 1, floatval( $input['root_px'] ?? 16 ) ),
			'tokens'    => [],
		];

		if ( $clean['max_vw'] <= $clean['min_vw'] ) {
			$clean['max_vw'] = $clean['min_vw'] + 1;
		}

		if ( ! empty( $input['tokens'] ) && is_array( $input['tokens'] ) ) {
			foreach ( $input['tokens'] as $token ) {
				$name = sanitize_title( $token['name'] ?? '' );
				$max  = floatval( $token['max'] ?? 0 );

				if ( ! $name || $max <= 0 ) {
					continue;
				}

				$clean['tokens'][] = [
					'name' => $name,
					'max'  => $max,
					'min'  => ( '' !== ( $token['min'] ?? '' ) ) ? floatval( $token['min'] ) : '',
				];
			}
		}

		return $clean;
	}

	public static function render_page() {
		$s = self::get_settings();
		?>
		<div class="wrap">
			<h1>Stoke Fluid Clamp</h1>
			<p>Enter a max px value per token. Min is calculated from the ratio unless you override it. Output lands in <code>&lt;head&gt;</code> as <code>:root</code> custom properties.</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'sfc_group' ); ?>

				<h2 class="title">Viewport Range</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sfc-min-vw">Min viewport (px)</label></th>
						<td><input type="number" id="sfc-min-vw" name="<?php echo esc_attr( self::OPTION ); ?>[min_vw]" value="<?php echo esc_attr( $s['min_vw'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sfc-max-vw">Max viewport (px)</label></th>
						<td><input type="number" id="sfc-max-vw" name="<?php echo esc_attr( self::OPTION ); ?>[max_vw]" value="<?php echo esc_attr( $s['max_vw'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sfc-ratio">Default min ratio</label></th>
						<td>
							<input type="number" id="sfc-ratio" name="<?php echo esc_attr( self::OPTION ); ?>[min_ratio]" value="<?php echo esc_attr( $s['min_ratio'] ); ?>" step="0.05" min="0.1" max="1" class="small-text">
							<p class="description">0.5 = min is half the max. Keep at 0.5 or above to satisfy the WCAG 1.4.4 resize heuristic.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sfc-root">Root font size (px)</label></th>
						<td><input type="number" id="sfc-root" name="<?php echo esc_attr( self::OPTION ); ?>[root_px]" value="<?php echo esc_attr( $s['root_px'] ); ?>" class="small-text"></td>
					</tr>
				</table>

				<h2 class="title">Tokens</h2>
				<table class="widefat striped" id="sfc-tokens" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:30%;">Variable name <span class="description">(no <code>--</code>)</span></th>
							<th style="width:15%;">Max px</th>
							<th style="width:20%;">Min px <span class="description">(blank = ratio)</span></th>
							<th>Generated value</th>
							<th style="width:60px;"></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$rows = $s['tokens'];
						if ( ! $rows ) {
							$rows = [ [ 'name' => '', 'max' => '', 'min' => '' ] ];
						}
						foreach ( $rows as $i => $token ) :
							$preview = '';
							if ( ! empty( $token['name'] ) && ! empty( $token['max'] ) ) {
								$min     = ( '' !== $token['min'] ) ? floatval( $token['min'] ) : round( floatval( $token['max'] ) * $s['min_ratio'], 2 );
								$preview = self::build_clamp( $min, floatval( $token['max'] ), $s['min_vw'], $s['max_vw'], $s['root_px'] );
							}
							?>
							<tr>
								<td><input type="text" name="<?php echo esc_attr( self::OPTION ); ?>[tokens][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $token['name'] ); ?>" placeholder="fs-display" class="regular-text" style="width:100%;"></td>
								<td><input type="number" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[tokens][<?php echo (int) $i; ?>][max]" value="<?php echo esc_attr( $token['max'] ); ?>" placeholder="107" class="small-text"></td>
								<td><input type="number" step="0.01" name="<?php echo esc_attr( self::OPTION ); ?>[tokens][<?php echo (int) $i; ?>][min]" value="<?php echo esc_attr( $token['min'] ); ?>" class="small-text"></td>
								<td><code style="user-select:all;"><?php echo esc_html( $preview ); ?></code></td>
								<td><button type="button" class="button sfc-remove">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p><button type="button" class="button" id="sfc-add">Add token</button></p>

				<?php submit_button( 'Save & Generate' ); ?>
			</form>
		</div>

		<script>
		( function () {
			const tbody  = document.querySelector( '#sfc-tokens tbody' );
			const option = <?php echo wp_json_encode( self::OPTION ); ?>;

			document.getElementById( 'sfc-add' ).addEventListener( 'click', function () {
				const i   = tbody.rows.length ? Math.max( ...[ ...tbody.querySelectorAll( 'input[name*="[name]"]' ) ].map( el => parseInt( el.name.match( /\[tokens\]\[(\d+)\]/ )[1], 10 ) ) ) + 1 : 0;
				const row = tbody.insertRow();
				row.innerHTML =
					'<td><input type="text" name="' + option + '[tokens][' + i + '][name]" placeholder="fs-h1" class="regular-text" style="width:100%;"></td>' +
					'<td><input type="number" step="0.01" name="' + option + '[tokens][' + i + '][max]" class="small-text"></td>' +
					'<td><input type="number" step="0.01" name="' + option + '[tokens][' + i + '][min]" class="small-text"></td>' +
					'<td><code></code></td>' +
					'<td><button type="button" class="button sfc-remove">&times;</button></td>';
			} );

			tbody.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'sfc-remove' ) ) {
					e.target.closest( 'tr' ).remove();
				}
			} );
		} )();
		</script>
		<?php
	}
}

Stoke_Fluid_Clamp::init();
