<?php
/**
 * Settings → OdooWoo.
 *
 * @package OdooWooPortal
 */

namespace OdooWooPortal;

defined( 'ABSPATH' ) || exit;

/**
 * One option, read through get(), edited on Settings → OdooWoo.
 */
final class Settings {

	public const OPTION = 'odoowoo_portal_settings';
	public const GROUP  = 'odoowoo_portal';
	public const PAGE   = 'odoowoo-portal';

	/**
	 * Hooks the settings screen.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	/**
	 * Defaults by key.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'connection_id'     => '',
			'software_slug'     => 'odoowoo',
			'list_page_id'      => 0,
			'dashboard_page_id' => 0,
			'delete_data'       => false,
		);
	}

	/**
	 * One setting, typed, with its default.
	 *
	 * @param string $key Setting.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$defaults = self::defaults();

		if ( ! array_key_exists( $key, $defaults ) ) {
			return null;
		}

		$saved = get_option( self::OPTION, array() );
		$value = is_array( $saved ) && array_key_exists( $key, $saved ) ? $saved[ $key ] : $defaults[ $key ];

		switch ( $key ) {
			case 'list_page_id':
			case 'dashboard_page_id':
				return (int) $value;
			case 'delete_data':
				return (bool) $value;
			case 'software_slug':
				$slug = sanitize_title( (string) $value );
				return '' === $slug ? $defaults[ $key ] : $slug;
			default:
				return (string) $value;
		}
	}

	/**
	 * Registers the option with its sanitizer.
	 */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Cleans a submission. A page id must be a published page; a connection id must exist.
	 *
	 * @param mixed $input Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		$connection = sanitize_key( (string) ( $input['connection_id'] ?? '' ) );

		if ( '' !== $connection && ! isset( \BeltoftServices\Http\Connections::all()[ $connection ] ) ) {
			self::reject( 'connection_id', __( 'That connection does not exist, so it was not saved.', 'odoowoo-portal' ) );
			$connection = '';
		}

		$clean['connection_id'] = $connection;

		$slug                   = sanitize_title( (string) ( $input['software_slug'] ?? '' ) );
		$clean['software_slug'] = '' === $slug ? $clean['software_slug'] : $slug;

		foreach ( array( 'list_page_id', 'dashboard_page_id' ) as $key ) {
			$id = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;

			if ( $id > 0 && ! ( 'page' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) ) {
				self::reject( $key, __( 'Choose a published page.', 'odoowoo-portal' ) );
				$id = 0;
			}

			$clean[ $key ] = $id;
		}

		$clean['delete_data'] = ! empty( $input['delete_data'] );

		return $clean;
	}

	/**
	 * Tells the admin a value was refused (when the Settings API is loaded).
	 *
	 * @param string $key     Setting key.
	 * @param string $message Message.
	 */
	private static function reject( string $key, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'odoowoo_portal_' . $key, $message );
		}
	}

	/**
	 * Adds Settings → OdooWoo.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'OdooWoo', 'odoowoo-portal' ),
			__( 'OdooWoo', 'odoowoo-portal' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Prints the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$name = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OdooWoo', 'odoowoo-portal' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="odoowoo-connection"><?php esc_html_e( 'Connection', 'odoowoo-portal' ); ?></label></th>
						<td>
							<select id="odoowoo-connection" name="<?php echo esc_attr( $name ); ?>[connection_id]">
								<option value=""><?php esc_html_e( '— Select —', 'odoowoo-portal' ); ?></option>
								<?php foreach ( \BeltoftServices\Http\Connections::all() as $id => $connection ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( self::get( 'connection_id' ), (string) $id ); ?>><?php echo esc_html( (string) ( $connection['label'] ?? $id ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'An HTTP connection from Beltoft Services. Its secret stays there.', 'odoowoo-portal' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="odoowoo-slug"><?php esc_html_e( 'License software slug', 'odoowoo-portal' ); ?></label></th>
						<td><input type="text" id="odoowoo-slug" class="regular-text" name="<?php echo esc_attr( $name ); ?>[software_slug]" value="<?php echo esc_attr( (string) self::get( 'software_slug' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="odoowoo-list-page"><?php esc_html_e( 'Dashboards page', 'odoowoo-portal' ); ?></label></th>
						<td>
							<?php self::pages_dropdown( 'list_page_id', 'odoowoo-list-page' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="odoowoo-dashboard-page"><?php esc_html_e( 'Dashboard page', 'odoowoo-portal' ); ?></label></th>
						<td>
							<?php self::pages_dropdown( 'dashboard_page_id', 'odoowoo-dashboard-page' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'odoowoo-portal' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data]" value="1" <?php checked( self::get( 'delete_data' ) ); ?>> <?php esc_html_e( 'Delete the settings and saved task data when the plugin is deleted', 'odoowoo-portal' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints a page dropdown for a setting.
	 *
	 * @param string $key Setting key.
	 * @param string $id  Element id.
	 */
	private static function pages_dropdown( string $key, string $id ): void {
		$selected = (int) self::get( $key );
		$pages    = get_pages( array( 'post_status' => 'publish' ) );

		printf( '<select name="%1$s" id="%2$s">', esc_attr( self::OPTION . '[' . $key . ']' ), esc_attr( $id ) );
		printf( '<option value="0">%s</option>', esc_html__( '— Select —', 'odoowoo-portal' ) );

		foreach ( is_array( $pages ) ? $pages : array() as $page ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $page->ID,
				selected( $selected, (int) $page->ID, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core returns a fixed attribute.
				esc_html( get_the_title( $page ) )
			);
		}

		echo '</select>';
	}
}
