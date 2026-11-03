<?php
namespace EventFlow;

defined( 'ABSPATH' ) || exit;

final class Settings {
	private const CURRENCIES = array( 'aud', 'cad', 'eur', 'gbp', 'inr', 'usd' );

	public static function currencies(): array {
		return self::CURRENCIES;
	}

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	public function register_settings(): void {
		register_setting( 'eventflow_settings_group', 'eventflow_settings', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => array() ) );
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$current = Stripe_Gateway::settings();
		$currency = isset( $input['currency'] ) ? strtolower( sanitize_text_field( $input['currency'] ) ) : 'usd';
		$output = array( 'currency' => in_array( $currency, self::CURRENCIES, true ) ? $currency : 'usd' );
		foreach ( array( 'stripe_secret_key', 'stripe_webhook_secret' ) as $key ) {
			$value = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';
			$output[ $key ] = '' !== $value ? $value : ( isset( $current[ $key ] ) ? $current[ $key ] : '' );
		}
		return $output;
	}

	public function register_page(): void {
		add_submenu_page( 'edit.php?post_type=ef_event', __( 'EventFlow settings', 'eventflow' ), __( 'Settings', 'eventflow' ), 'manage_options', 'eventflow-settings', array( $this, 'render_page' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Stripe_Gateway::settings();
		$currency = isset( $settings['currency'] ) ? $settings['currency'] : 'usd';
		?>
		<div class="wrap"><h1><?php esc_html_e( 'EventFlow settings', 'eventflow' ); ?></h1><form action="options.php" method="post">
			<?php settings_fields( 'eventflow_settings_group' ); ?>
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><label for="eventflow-currency"><?php esc_html_e( 'Currency', 'eventflow' ); ?></label></th><td><select id="eventflow-currency" name="eventflow_settings[currency]"><?php foreach ( self::CURRENCIES as $choice ) : ?><option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $currency, $choice ); ?>><?php echo esc_html( strtoupper( $choice ) ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Currency applies to paid event tickets. Set each event ticket amount in this currency.', 'eventflow' ); ?></p></td></tr>
			<tr><th scope="row"><label for="eventflow-stripe-secret"><?php esc_html_e( 'Stripe secret key', 'eventflow' ); ?></label></th><td><input autocomplete="new-password" class="regular-text" id="eventflow-stripe-secret" name="eventflow_settings[stripe_secret_key]" type="password" value=""><?php if ( ! empty( $settings['stripe_secret_key'] ) || defined( 'EVENTFLOW_STRIPE_SECRET_KEY' ) ) : ?><p><?php esc_html_e( 'A secret key is saved.', 'eventflow' ); ?></p><?php endif; ?><p class="description"><?php esc_html_e( 'Use a restricted or standard Stripe secret key. Leave blank to keep the saved value. You may instead define EVENTFLOW_STRIPE_SECRET_KEY in wp-config.php.', 'eventflow' ); ?></p></td></tr>
			<tr><th scope="row"><label for="eventflow-stripe-webhook"><?php esc_html_e( 'Stripe webhook signing secret', 'eventflow' ); ?></label></th><td><input autocomplete="new-password" class="regular-text" id="eventflow-stripe-webhook" name="eventflow_settings[stripe_webhook_secret]" type="password" value=""><?php if ( ! empty( $settings['stripe_webhook_secret'] ) || defined( 'EVENTFLOW_STRIPE_WEBHOOK_SECRET' ) ) : ?><p><?php esc_html_e( 'A webhook signing secret is saved.', 'eventflow' ); ?></p><?php endif; ?><p class="description"><?php esc_html_e( 'Set the signing secret for the webhook endpoint shown below. You may instead define EVENTFLOW_STRIPE_WEBHOOK_SECRET in wp-config.php.', 'eventflow' ); ?></p></td></tr>
			</tbody></table>
			<p><strong><?php esc_html_e( 'Webhook endpoint:', 'eventflow' ); ?></strong> <code><?php echo esc_html( rest_url( 'eventflow/v1/stripe-webhook' ) ); ?></code></p>
			<p><?php esc_html_e( 'Subscribe to checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, and refund.updated. Stripe is contacted when an attendee starts a paid booking or an authorized event owner requests a full refund.', 'eventflow' ); ?></p>
			<?php submit_button(); ?>
		</form></div>
		<?php
	}
}
