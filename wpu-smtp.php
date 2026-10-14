<?php
/**
 * Plugin Name:       WPU SMTP
 * Plugin URI:        https://wordpressutveckling.se/
 * Description:       Skickar WordPress e-post via din e-postleverantörs SMTP-server i stället för webbserverns egen mejlfunktion. Inställningssida med testmejl; lösenordet sparas krypterat.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Pihlfelt & Vänner
 * Author URI:        https://pihlfelt.se
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpu-smtp
 */

defined( 'ABSPATH' ) || exit;

/*
 * Everything is set on the settings page (Inställningar → SMTP). The password is stored
 * encrypted with the site's own keys from wp-config.php, never in plain text.
 *
 * Optional: a constant in wp-config.php locks a value and overrides the page, e.g. on
 * agency sites where the customer should not change the mail server:
 *
 *   define( 'WPU_SMTP_HOST', 'mailcluster.loopia.se' );
 *   define( 'WPU_SMTP_PORT', 587 );
 *   define( 'WPU_SMTP_SECURE', 'tls' );            // 'tls' (STARTTLS), 'ssl' or ''
 *   define( 'WPU_SMTP_USER', 'info@example.se' );
 *   define( 'WPU_SMTP_PASSWORD', '…' );            // keep the password here, not in the database
 *   define( 'WPU_SMTP_FROM', 'info@example.se' );
 *   define( 'WPU_SMTP_FROM_NAME', 'Example' );
 */

const WPU_SMTP_FIELDS = array( 'host', 'port', 'secure', 'user', 'password', 'from', 'from_name' );

/** One setting: the constant wins, then the saved option. */
function wpu_smtp_get( string $key ): string {
	$constant = 'WPU_SMTP_' . strtoupper( $key );
	if ( defined( $constant ) ) {
		return (string) constant( $constant );
	}
	$saved = (array) get_option( 'wpu_smtp', array() );
	$value = (string) ( $saved[ $key ] ?? '' );
	if ( 'password' === $key ) {
		return wpu_smtp_decrypt( $value );
	}
	if ( '' === $value && 'port' === $key ) {
		return '587';
	}
	if ( '' === $value && 'secure' === $key ) {
		return 'tls';
	}
	return $value;
}

function wpu_smtp_from_constant( string $key ): bool {
	return defined( 'WPU_SMTP_' . strtoupper( $key ) );
}

/* Password encryption --------------------------------------------------------
 * libsodium ships with PHP. The key is derived from the AUTH salts in wp-config.php, so a
 * database dump alone does not reveal the password. If the salts change, the password
 * simply has to be entered again.
 */

function wpu_smtp_key(): string {
	return hash( 'sha256', wp_salt( 'auth' ) . 'wpu-smtp', true );
}

function wpu_smtp_encrypt( string $plain ): string {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	return 'enc:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, wpu_smtp_key() ) );
}

function wpu_smtp_decrypt( string $stored ): string {
	if ( ! str_starts_with( $stored, 'enc:' ) ) {
		return $stored; // Empty, or saved by an older version.
	}
	$raw   = base64_decode( substr( $stored, 4 ), true );
	$nonce = $raw ? substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) : '';
	$box   = $raw ? substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) : '';
	$plain = ( $nonce && $box ) ? sodium_crypto_secretbox_open( $box, $nonce, wpu_smtp_key() ) : false;
	return false === $plain ? '' : $plain;
}

/** Nothing changes until host and user are set, so activating the plugin never breaks mail. */
function wpu_smtp_ready(): bool {
	return '' !== wpu_smtp_get( 'host' ) && '' !== wpu_smtp_get( 'user' ) && '' !== wpu_smtp_get( 'password' );
}

/* 1. Send through SMTP ------------------------------------------------------ */

add_action( 'phpmailer_init', 'wpu_smtp_phpmailer' );
function wpu_smtp_phpmailer( $mailer ): void {
	if ( ! wpu_smtp_ready() ) {
		return;
	}
	$mailer->isSMTP();
	$mailer->Host     = wpu_smtp_get( 'host' );
	$mailer->Port     = (int) wpu_smtp_get( 'port' );
	$mailer->SMTPAuth = true;
	$mailer->Username = wpu_smtp_get( 'user' );
	$mailer->Password = wpu_smtp_get( 'password' );
	$secure           = wpu_smtp_get( 'secure' );
	$mailer->SMTPSecure  = in_array( $secure, array( 'tls', 'ssl' ), true ) ? $secure : '';
	$mailer->SMTPAutoTLS = 'tls' === $secure;
	$mailer->Timeout     = 15;

	// Most providers only accept mail from the logged-in account, so the sender follows it.
	$from = wpu_smtp_get( 'from' ) ?: wpu_smtp_get( 'user' );
	$mailer->setFrom( $from, wpu_smtp_get( 'from_name' ) ?: get_bloginfo( 'name' ), false );
	// Bounces go to the same address instead of the web server's account.
	$mailer->Sender = $from;
}

// Keep the latest error so the settings page can show why a mail failed.
add_action( 'wp_mail_failed', 'wpu_smtp_remember_error' );
function wpu_smtp_remember_error( WP_Error $error ): void {
	update_option( 'wpu_smtp_last_error', gmdate( 'Y-m-d H:i' ) . ' UTC: ' . $error->get_error_message(), false );
}

/* 2. Settings page (Inställningar → SMTP) ------------------------------------ */

add_action( 'admin_menu', 'wpu_smtp_menu' );
function wpu_smtp_menu(): void {
	add_options_page( 'SMTP', 'SMTP', 'manage_options', 'wpu-smtp', 'wpu_smtp_page' );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpu_smtp_action_links' );
function wpu_smtp_action_links( array $links ): array {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=wpu-smtp' ) ) . '">' . esc_html__( 'Inställningar', 'wpu-smtp' ) . '</a>' );
	return $links;
}

add_action( 'admin_init', 'wpu_smtp_register_setting' );
function wpu_smtp_register_setting(): void {
	register_setting( 'wpu_smtp', 'wpu_smtp', array( 'type' => 'array', 'sanitize_callback' => 'wpu_smtp_sanitize', 'default' => array() ) );
}

function wpu_smtp_sanitize( $in ): array {
	$in  = is_array( $in ) ? $in : array();
	$old = (array) get_option( 'wpu_smtp', array() );
	return array(
		'host'      => sanitize_text_field( (string) ( $in['host'] ?? '' ) ),
		'port'      => (string) ( absint( $in['port'] ?? 0 ) ?: 587 ),
		'secure'    => in_array( $in['secure'] ?? 'tls', array( 'tls', 'ssl', '' ), true ) ? (string) ( $in['secure'] ?? 'tls' ) : 'tls',
		'user'      => sanitize_text_field( (string) ( $in['user'] ?? '' ) ),
		// An empty password field means "keep the current one"; the saved password is never printed.
		'password'  => '' !== (string) ( $in['password'] ?? '' ) ? wpu_smtp_encrypt( (string) $in['password'] ) : (string) ( $old['password'] ?? '' ),
		'from'      => sanitize_email( (string) ( $in['from'] ?? '' ) ),
		'from_name' => sanitize_text_field( (string) ( $in['from_name'] ?? '' ) ),
	);
}

// The test mail is sent on a normal form post so the result can be shown after reload.
add_action( 'admin_post_wpu_smtp_test', 'wpu_smtp_send_test' );
function wpu_smtp_send_test(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Du har inte behörighet.', 'wpu-smtp' ) );
	}
	check_admin_referer( 'wpu_smtp_test' );
	$to = sanitize_email( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) ) ?: get_option( 'admin_email' );
	delete_option( 'wpu_smtp_last_error' );
	$ok = wp_mail(
		$to,
		sprintf( /* translators: %s: site name */ __( 'Testmejl från %s', 'wpu-smtp' ), get_bloginfo( 'name' ) ),
		__( "Det här är ett testmejl från WPU SMTP.\n\nKom det fram till inkorgen (inte skräpposten) fungerar utskicken.", 'wpu-smtp' )
	);
	wp_safe_redirect( add_query_arg( array( 'page' => 'wpu-smtp', 'test' => $ok ? 'ok' : 'fel' ), admin_url( 'options-general.php' ) ) );
	exit;
}

function wpu_smtp_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$test  = sanitize_key( (string) ( $_GET['test'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$error = (string) get_option( 'wpu_smtp_last_error', '' );
	$field = static function ( string $key, string $label, string $type = 'text', string $help = '' ): void {
		$locked = wpu_smtp_from_constant( $key );
		$value  = 'password' === $key ? '' : wpu_smtp_get( $key );
		printf(
			'<tr><th scope="row"><label for="wpu-smtp-%1$s">%2$s</label></th><td><input id="wpu-smtp-%1$s" type="%3$s" class="regular-text" name="wpu_smtp[%1$s]" value="%4$s" %5$s autocomplete="off"><p class="description">%6$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$locked ? 'disabled' : '',
			esc_html( $locked ? __( 'Satt i wp-config.php.', 'wpu-smtp' ) : $help )
		);
	};
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SMTP', 'wpu-smtp' ); ?></h1>
		<?php if ( 'ok' === $test ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Testmejlet skickades. Kontrollera att det kom till inkorgen och inte till skräpposten.', 'wpu-smtp' ); ?></p></div>
		<?php elseif ( 'fel' === $test ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( __( 'Testmejlet kunde inte skickas.', 'wpu-smtp' ) . ( $error ? ' ' . $error : '' ) ); ?></p></div>
		<?php endif; ?>
		<p><?php echo wpu_smtp_ready() ? esc_html__( 'Status: e-posten skickas via SMTP.', 'wpu-smtp' ) : esc_html__( 'Status: inte konfigurerat ännu. E-posten skickas med serverns egen mejlfunktion tills server, användare och lösenord är ifyllda.', 'wpu-smtp' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpu_smtp' ); ?>
			<table class="form-table" role="presentation">
				<?php
				$field( 'host', __( 'SMTP-server', 'wpu-smtp' ), 'text', __( 'Till exempel mailcluster.loopia.se, smtp.office365.com eller smtp.gmail.com.', 'wpu-smtp' ) );
				$field( 'port', __( 'Port', 'wpu-smtp' ), 'number', __( '587 med TLS (vanligast) eller 465 med SSL.', 'wpu-smtp' ) );
				?>
				<tr><th scope="row"><label for="wpu-smtp-secure"><?php esc_html_e( 'Kryptering', 'wpu-smtp' ); ?></label></th><td>
					<select id="wpu-smtp-secure" name="wpu_smtp[secure]" <?php disabled( wpu_smtp_from_constant( 'secure' ) ); ?>>
						<?php foreach ( array( 'tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', '' => __( 'Ingen', 'wpu-smtp' ) ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( wpu_smtp_get( 'secure' ), $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<?php
				$field( 'user', __( 'Användarnamn', 'wpu-smtp' ), 'text', __( 'Oftast hela e-postadressen.', 'wpu-smtp' ) );
				$field( 'password', __( 'Lösenord', 'wpu-smtp' ), 'password', '' !== wpu_smtp_get( 'password' ) ? __( 'Sparat (krypterat). Lämna tomt för att behålla det.', 'wpu-smtp' ) : __( 'Sparas krypterat med sajtens nycklar från wp-config.php.', 'wpu-smtp' ) );
				$field( 'from', __( 'Avsändaradress', 'wpu-smtp' ), 'email', __( 'Tomt = användarnamnet. Måste oftast vara samma konto.', 'wpu-smtp' ) );
				$field( 'from_name', __( 'Avsändarnamn', 'wpu-smtp' ), 'text', __( 'Tomt = sajtens namn.', 'wpu-smtp' ) );
				?>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Skicka ett testmejl', 'wpu-smtp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wpu_smtp_test' ); ?>
			<input type="hidden" name="action" value="wpu_smtp_test">
			<input type="email" class="regular-text" name="to" value="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
			<?php submit_button( __( 'Skicka testmejl', 'wpu-smtp' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( $error && 'fel' !== $test ) : ?>
			<p><strong><?php esc_html_e( 'Senaste fel:', 'wpu-smtp' ); ?></strong> <?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
