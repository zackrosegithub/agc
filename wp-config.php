<?php
/** Enable W3 Total Cache */
define('WP_CACHE', true); // Added by W3 Total Cache

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'actiftpw_wp553' );

/** MySQL database username */
define( 'DB_USER', 'actiftpw_wp553' );

/** MySQL database password */
define( 'DB_PASSWORD', 'V4!)9]S4]W7S8p' );

/** MySQL hostname */
define( 'DB_HOST', 'localhost' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'kf8hmrdgl9voec0x6vbtvgqxcuv48ts8uxjqx6v9bwngjxwgldh6hum2ltzhewjw' );
define( 'SECURE_AUTH_KEY',  'dfgf6junv9weoz2ubw7mrzvbhhaviihbttlx8r0mi3as1ht76y0g8s3ubwgbqa3p' );
define( 'LOGGED_IN_KEY',    'hv4yhkq56xhfehif8jj0ughu4bspxfc4uixbgg0slmx5ghfrnzmltttjwz98w2na' );
define( 'NONCE_KEY',        'wphrxtussiegrrbqt8mc3ufhbscjqnrciijxkubtjgohhvrzyh8jtgxx4vhtbdfe' );
define( 'AUTH_SALT',        'vljtejgkpaldyg3ecsvq4nbuolp0xsw98ykct13v093ejoir4jm0dxvtsrvhulwp' );
define( 'SECURE_AUTH_SALT', 'm2ewi4w0bzwgj0qpmt0syzturkzufojsmadv13cybhizuhoagjs5ccm4lav7ag8o' );
define( 'LOGGED_IN_SALT',   'lvl5socqokize4heyy6cgl5i5ij4vh1oqilw9tmr9clewxl4mwqn4umkfqjgm7zt' );
define( 'NONCE_SALT',       '1yt3yn6fnc8a0tloigaqez169fwd5xjgbjow3sm6ao8otsceoksuslaiuplwdtv6' );

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wpvh_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
