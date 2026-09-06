<?php
/**
 * WordPress Installer
 *
 * @package WordPress
 * @subpackage Administration
 */

// Confidence check.
if ( false ) {
	?>
<!DOCTYPE html>
<html lang="en-US">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<title>Error: PHP is not running</title>
</head>
<body class="wp-core-ui admin-color-modern">
	<h1>Error: PHP is not running</h1>
	<p>AstraeaOS WP requires PHP. Your web server does not have PHP enabled or PHP is unavailable.</p>
</body>
</html>
	<?php
}

/**
 * We are installing WordPress.
 *
 * @since 1.5.1
 * @var bool
 */
define( 'WP_INSTALLING', true );

/** Load WordPress Bootstrap */
require_once dirname( __DIR__ ) . '/wp-load.php';

/** Load WordPress Administration Upgrade API */
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

/** Load WordPress Translation Install API */
require_once ABSPATH . 'wp-admin/includes/translation-install.php';

/** Load wpdb */
require_once ABSPATH . WPINC . '/class-wpdb.php';

/** AstraeaOS Secure Genesis installer. */
require_once ASTRAEA_CORE_DIR . 'Installer/SecureGenesis.php';
require_once ABSPATH . 'wp-admin/includes/astraea-secure-genesis-view.php';

nocache_headers();

$step = isset( $_GET['step'] ) ? (int) $_GET['step'] : 0;

/**
 * Display installation header.
 *
 * @since 2.5.0
 *
 * @param string $body_classes Class attribute values for the body tag.
 */
function display_header( $body_classes = '' ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	if ( is_rtl() ) {
		$body_classes .= 'rtl';
	}
	if ( $body_classes ) {
		$body_classes = ' ' . $body_classes;
	}
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php _e( 'AstraeaOS WP &rsaquo; Installation' ); ?></title>
	<?php wp_admin_css( 'install', true ); ?>
	<link rel="stylesheet" href="<?php echo esc_url( admin_url( 'css/astraea-install.css' ) ); ?>" />
	<script src="<?php echo esc_url( admin_url( 'js/astraea-secure-genesis.js?ver=' . rawurlencode( defined( 'ASTRAEA_VERSION' ) ? ASTRAEA_VERSION : 'dev' ) ) ); ?>" defer></script>
</head>
<body class="wp-core-ui admin-color-modern astraea-installer<?php echo $body_classes; ?>">
<p id="logo"><?php _e( 'AstraeaOS WP' ); ?></p>

	<?php
} // End display_header().

/**
 * Displays installer setup form.
 *
 * @since 2.8.0
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string|null $error Error message to display, if any.
 */
function display_setup_form( $error = null ) {
	astraea_display_secure_genesis_form( is_string( $error ) ? $error : null );
} // End display_setup_form().

// Let's check to make sure WP isn't already installed. An interrupted Secure
// Genesis transaction is the only supported post-core-install exception.
if ( is_blog_installed() ) {
	if ( 3 === $step && \Astraea\Installer\SecureGenesis::canResume() ) {
		display_header( 'astraea-secure-genesis-resume' );
		try {
			if ( ! \Astraea\Installer\SecureGenesis::hasRecoveryKeyHash() ) {
				if ( isset( $_POST['astraea_genesis_recovery_retry'] ) ) {
					check_admin_referer( 'astraea_genesis_recovery_resume' );
					$recovery_key = isset( $_POST['astraea_recovery_key'] ) ? wp_unslash( (string) $_POST['astraea_recovery_key'] ) : '';
					$recovery_ack = ! empty( $_POST['astraea_recovery_ack'] );
					if ( ! $recovery_ack ) {
						throw new \Astraea\Exceptions\ValidationException( 'Confirm that the Secure Genesis recovery key was stored before continuing.' );
					}
					\Astraea\Installer\SecureGenesis::provisionRecoveryKeyForResume( $recovery_key );
				} else {
					astraea_render_genesis_recovery_resume();
					echo '</body></html>';
					exit;
				}
			}
			$report = \Astraea\Installer\SecureGenesis::resume();
			$current = wp_get_current_user();
			$label = $current->exists() ? (string) $current->user_login : 'Astraea Master';
			astraea_render_genesis_report( $report, $label );
		} catch ( \Astraea\Exceptions\ValidationException $e ) {
			if ( ! \Astraea\Installer\SecureGenesis::hasRecoveryKeyHash() ) {
				astraea_render_genesis_recovery_resume( $e->getMessage() );
			} else {
				echo '<div class="astraea-genesis-alert is-error"><strong>Resume rejected.</strong><span>' . esc_html( $e->getMessage() ) . '</span></div>';
			}
		} catch ( \Astraea\Exceptions\SecurityException | \Astraea\Exceptions\StorageException $e ) {
			$incident = 'genesis_' . bin2hex( random_bytes( 6 ) );
			error_log( '[ASTRAEA GENESIS] ' . $incident . ' ' . $e->getMessage() );
			if ( ! \Astraea\Installer\SecureGenesis::hasRecoveryKeyHash() ) {
				astraea_render_genesis_recovery_resume( 'Security state could not commit the recovery key. Incident ' . $incident . '.' );
			} else {
				echo '<div class="astraea-genesis-alert is-error"><strong>Security compilation failed.</strong><span>Incident ' . esc_html( $incident ) . '</span></div>';
			}
		}
		echo '</body></html>';
		exit;
	}
	display_header();
	die(
		'<h1>' . __( 'Already Installed' ) . '</h1>' .
		'<p>' . __( 'AstraeaOS WP is already installed. To perform a clean reinstall, remove the existing database tables first.' ) . '</p>' .
		'<p class="step"><a href="' . esc_url( wp_login_url() ) . '">' . __( 'Log In' ) . '</a></p>' .
		'</body></html>'
	);
}

/**
 * @global string   $wp_version              The WordPress version string.
 * @global string   $required_php_version    The minimum required PHP version string.
 * @global string[] $required_php_extensions The names of required PHP extensions.
 * @global string   $required_mysql_version  The minimum required MySQL version string.
 * @global wpdb     $wpdb                    WordPress database abstraction object.
 */
global $wp_version, $required_php_version, $required_php_extensions, $required_mysql_version, $wpdb;

$php_version   = PHP_VERSION;
$mysql_version = $wpdb->db_version();
$php_compat    = version_compare( $php_version, $required_php_version, '>=' );
$mysql_compat  = version_compare( $mysql_version, $required_mysql_version, '>=' ) || file_exists( WP_CONTENT_DIR . '/db.php' );

$version_url = sprintf(
	/* translators: %s: WordPress version. */
	esc_url( __( 'https://wordpress.org/documentation/wordpress-version/version-%s/' ) ),
	sanitize_title( $wp_version )
);

$php_update_message = '</p><p>' . sprintf(
	/* translators: %s: URL to Update PHP page. */
	__( '<a href="%s">Learn more about updating PHP</a>.' ),
	esc_url( wp_get_update_php_url() )
);

$annotation = wp_get_update_php_annotation();

if ( $annotation ) {
	$php_update_message .= '</p><p><em>' . $annotation . '</em>';
}

if ( ! $mysql_compat && ! $php_compat ) {
	$compat = sprintf(
		/* translators: 1: URL to WordPress release notes, 2: WordPress version number, 3: Minimum required PHP version number, 4: Minimum required MySQL version number, 5: Current PHP version number, 6: Current MySQL version number. */
		__( 'You cannot install because <a href="%1$s">AstraeaOS WP %2$s</a> requires PHP version %3$s or higher and MySQL version %4$s or higher. You are running PHP version %5$s and MySQL version %6$s.' ),
		$version_url,
		$wp_version,
		$required_php_version,
		$required_mysql_version,
		$php_version,
		$mysql_version
	) . $php_update_message;
} elseif ( ! $php_compat ) {
	$compat = sprintf(
		/* translators: 1: URL to WordPress release notes, 2: WordPress version number, 3: Minimum required PHP version number, 4: Current PHP version number. */
		__( 'You cannot install because <a href="%1$s">AstraeaOS WP %2$s</a> requires PHP version %3$s or higher. You are running version %4$s.' ),
		$version_url,
		$wp_version,
		$required_php_version,
		$php_version
	) . $php_update_message;
} elseif ( ! $mysql_compat ) {
	$compat = sprintf(
		/* translators: 1: URL to WordPress release notes, 2: WordPress version number, 3: Minimum required MySQL version number, 4: Current MySQL version number. */
		__( 'You cannot install because <a href="%1$s">AstraeaOS WP %2$s</a> requires MySQL version %3$s or higher. You are running version %4$s.' ),
		$version_url,
		$wp_version,
		$required_mysql_version,
		$mysql_version
	);
}

if ( ! $mysql_compat || ! $php_compat ) {
	display_header();
	die( '<h1>' . __( 'Requirements Not Met' ) . '</h1><p>' . $compat . '</p></body></html>' );
}

if ( isset( $required_php_extensions ) && is_array( $required_php_extensions ) ) {
	$missing_extensions = array();

	foreach ( $required_php_extensions as $extension ) {
		if ( extension_loaded( $extension ) ) {
			continue;
		}

		$missing_extensions[] = sprintf(
			/* translators: 1: URL to WordPress release notes, 2: WordPress version number, 3: The PHP extension name needed. */
			__( 'You cannot install because <a href="%1$s">AstraeaOS WP %2$s</a> requires the %3$s PHP extension.' ),
			$version_url,
			$wp_version,
			$extension
		);
	}

	if ( count( $missing_extensions ) > 0 ) {
		display_header();
		die( '<h1>' . __( 'Requirements Not Met' ) . '</h1><p>' . implode( '</p><p>', $missing_extensions ) . '</p></body></html>' );
	}
}

if ( ! is_string( $wpdb->base_prefix ) || '' === $wpdb->base_prefix ) {
	display_header();
	die(
		'<h1>' . __( 'Configuration Error' ) . '</h1>' .
		'<p>' . sprintf(
			/* translators: %s: wp-config.php */
			__( 'Your %s file has an empty database table prefix, which is not supported.' ),
			'<code>wp-config.php</code>'
		) . '</p></body></html>'
	);
}

// Set error message if DO_NOT_UPGRADE_GLOBAL_TABLES isn't set as it will break install.
if ( defined( 'DO_NOT_UPGRADE_GLOBAL_TABLES' ) ) {
	display_header();
	die(
		'<h1>' . __( 'Configuration Error' ) . '</h1>' .
		'<p>' . sprintf(
			/* translators: %s: DO_NOT_UPGRADE_GLOBAL_TABLES */
			__( 'The constant %s cannot be defined while installing AstraeaOS WP.' ),
			'<code>DO_NOT_UPGRADE_GLOBAL_TABLES</code>'
		) . '</p></body></html>'
	);
}

/**
 * @global string    $wp_local_package Locale code of the package.
 * @global WP_Locale $wp_locale        WordPress date and time locale object.
 * @global wpdb      $wpdb             WordPress database abstraction object.
 */
$language = '';
if ( ! empty( $_REQUEST['language'] ) ) {
	$language = sanitize_locale_name( $_REQUEST['language'] );
} elseif ( isset( $GLOBALS['wp_local_package'] ) ) {
	$language = $GLOBALS['wp_local_package'];
}

$scripts_to_print = array( 'jquery' );

switch ( $step ) {
	case 0: // Step 0.
		if ( wp_can_install_language_pack() && empty( $language ) ) {
			$languages = wp_get_available_translations();
			if ( $languages ) {
				$scripts_to_print[] = 'language-chooser';
				display_header( 'language-chooser' );
				echo '<form id="setup" method="post" action="?step=1">';
				wp_install_language_form( $languages );
				echo '</form>';
				break;
			}
		}

		// Deliberately fall through if we can't reach the translations API.

	case 1: // Step 1, direct link or from language chooser.
		if ( ! empty( $language ) ) {
			$loaded_language = wp_download_language_pack( $language );
			if ( $loaded_language ) {
				load_default_textdomain( $loaded_language );
				$GLOBALS['wp_locale'] = new WP_Locale();
			}
		}

		$scripts_to_print[] = 'user-profile';

		display_header();
		?>
<?php
		display_setup_form();
		break;
	case 2:
		if ( ! empty( $language ) && load_default_textdomain( $language ) ) {
			$loaded_language      = $language;
			$GLOBALS['wp_locale'] = new WP_Locale();
		} else {
			$loaded_language = 'en_US';
		}

		if ( ! empty( $wpdb->error ) ) {
			wp_die( $wpdb->error->get_error_message() );
		}

		$scripts_to_print[] = 'user-profile';

		display_header();
		// Fill in the data we gathered.
		$weblog_title         = isset( $_POST['weblog_title'] ) ? trim( wp_unslash( $_POST['weblog_title'] ) ) : '';
		$user_name            = isset( $_POST['user_name'] ) ? trim( wp_unslash( $_POST['user_name'] ) ) : '';
		$admin_password       = isset( $_POST['admin_password'] ) ? wp_unslash( $_POST['admin_password'] ) : '';
		$admin_password_check = isset( $_POST['admin_password2'] ) ? wp_unslash( $_POST['admin_password2'] ) : '';
		$admin_email          = isset( $_POST['admin_email'] ) ? trim( wp_unslash( $_POST['admin_email'] ) ) : '';
		$public               = isset( $_POST['blog_public'] ) ? (int) $_POST['blog_public'] : 1;
		$genesis_input        = isset( $_POST['astraea_genesis'] ) && is_array( $_POST['astraea_genesis'] ) ? wp_unslash( $_POST['astraea_genesis'] ) : array();
		$genesis_plan         = \Astraea\Installer\SecureGenesis::sanitizePlan( $genesis_input );
		$recovery_key        = isset( $_POST['astraea_recovery_key'] ) && is_string( $_POST['astraea_recovery_key'] ) ? wp_unslash( $_POST['astraea_recovery_key'] ) : '';
		$recovery_ack        = isset( $_POST['astraea_recovery_ack'] ) && '1' === (string) $_POST['astraea_recovery_ack'];

		// Check email address.
		$error = false;
		if ( empty( $user_name ) ) {
			display_setup_form( __( 'Please provide a valid username.' ) );
			$error = true;
		} elseif ( sanitize_user( $user_name, true ) !== $user_name ) {
			display_setup_form( __( 'The username you provided has invalid characters.' ) );
			$error = true;
		} elseif ( $admin_password !== $admin_password_check ) {
			display_setup_form( __( 'Your passwords do not match. Please try again.' ) );
			$error = true;
		} elseif ( ! username_exists( $user_name ) ) {
			try {
				\Astraea\Installer\SecureGenesis::validateMasterPassword( (string) $admin_password, (string) $user_name, (string) $admin_email );
			} catch ( \Astraea\Exceptions\ValidationException $e ) {
				display_setup_form( esc_html( $e->getMessage() ) );
				$error = true;
			} catch ( \Astraea\Exceptions\SecurityException $e ) {
				error_log( '[ASTRAEA GENESIS] Master password security validation rejected input.' );
				display_setup_form( __( 'The Master password contains characters that are not permitted by the Secure Genesis policy.' ) );
				$error = true;
			}
		}

		if ( false === $error && empty( $admin_email ) ) {
			display_setup_form( __( 'You must provide an email address.' ) );
			$error = true;
		} elseif ( false === $error && ! is_email( $admin_email ) ) {
			display_setup_form( __( 'Sorry, that is not a valid email address. Email addresses look like <code>username@example.com</code>.' ) );
			$error = true;
		} elseif ( false === $error && ( ! $recovery_ack || ! \Astraea\Installer\SecureGenesis::validateRecoveryKey( $recovery_key ) ) ) {
			display_setup_form( __( 'Store and confirm the ThroneGuard recovery key before installation.' ) );
			$error = true;
		} elseif ( false === $error && \Astraea\Installer\SecureGenesis::preflightBlocksInstallation() ) {
			display_setup_form( __( 'One or more blocking Secure Genesis preflight checks failed.' ) );
			$error = true;
		}

		if ( false === $error ) {
			$wpdb->show_errors();
			$result = wp_install( $weblog_title, $user_name, $admin_email, $public, '', wp_slash( $admin_password ), $loaded_language );
			try {
				$user_id = isset( $result['user_id'] ) ? (int) $result['user_id'] : 0;
				\Astraea\Installer\SecureGenesis::beginTransaction( $user_id, $genesis_plan, $recovery_key );
				$report = \Astraea\Installer\SecureGenesis::compile( $user_id, $genesis_plan );
				astraea_render_genesis_report( $report, sanitize_user( $user_name, true ) );
			} catch ( \Astraea\Exceptions\ValidationException $e ) {
				update_option( 'astraea_install_state', 'SECURITY_FAILED', false );
				echo '<div class="astraea-genesis-alert is-error"><strong>Secure Genesis validation failed.</strong><span>' . esc_html( $e->getMessage() ) . '</span></div>';
			} catch ( \Astraea\Exceptions\SecurityException | \Astraea\Exceptions\StorageException | \Throwable $e ) {
				update_option( 'astraea_install_state', 'SECURITY_FAILED', false );
				$incident = 'genesis_' . bin2hex( random_bytes( 6 ) );
				error_log( '[ASTRAEA GENESIS] ' . $incident . ' ' . $e->getMessage() );
				echo '<div class="astraea-genesis-alert is-error"><strong>Security compilation failed.</strong><span>The WordPress core is installed, but Astraea did not mark the system READY. Incident ' . esc_html( $incident ) . '. Re-open <code>wp-admin/install.php?step=3</code> to resume.</span></div>';
			}
		}
		break;
}

if ( ! wp_is_mobile() ) {
	?>
<script>var t = document.getElementById('weblog_title'); if (t){ t.focus(); }</script>
	<?php
}

wp_print_scripts( $scripts_to_print );
?>
<script>
jQuery( function( $ ) {
	$( '.hide-if-no-js' ).removeClass( 'hide-if-no-js' );
} );
</script>
</body>
</html>
