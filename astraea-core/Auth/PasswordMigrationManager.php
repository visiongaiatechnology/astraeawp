<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Auth;

use WP_User;
use WP_Error;
use Astraea\Security\SecurityEventManager;

/**
 * Automated Legacy Password Migration Manager.
 *
 * Implements the "Verify Legacy -> Rehash Modern" lifecycle.
 *
 * Upon successful password verification of any legacy hash (phpass, MD5, $wp bcrypt),
 * this manager intercepts the authenticated user object, checks if `needsRehash()` returns true,
 * and immediately replaces the obsolete hash in the database with a modern Argon2id hash.
 *
 * @package Astraea\Auth
 */
final class PasswordMigrationManager {

    private static bool $hooked = false;

    /**
     * Attach migration hooks into WordPress authentication pipeline.
     */
    public static function init(): void {
        if (self::$hooked) {
            return;
        }

        // Intercept user authentication prior to session issuance
        add_filter('authenticate', [self::class, 'interceptAuthentication'], 25, 3);

        self::$hooked = true;
    }

    /**
     * Intercept authentication results to perform automatic on-the-fly rehash.
     *
     * @param WP_User|WP_Error|null $user Authenticated user or error.
     * @param string $username Username or email.
     * @param string $password Plaintext password provided by the user.
     * @return WP_User|WP_Error|null
     */
    public static function interceptAuthentication(
        mixed $user,
        string $username,
        #[\SensitiveParameter]
        string $password
    ): mixed {
        if (!($user instanceof WP_User) || empty($password)) {
            return $user;
        }

        $currentHash = (string) $user->user_pass;

        if (PasswordService::needsRehash($currentHash)) {
            // Rehash immediately to Argon2id and update database record
            $newHash = PasswordService::hash($password);

            global $wpdb;
            if (isset($wpdb)) {
                $wpdb->update(
                    $wpdb->users,
                    ['user_pass' => $newHash],
                    ['ID' => $user->ID],
                    ['%s'],
                    ['%d']
                );

                clean_user_cache($user->ID);
                $user->user_pass = $newHash;

                SecurityEventManager::recordEvent(
                    SecurityEventManager::SEVERITY_INFO,
                    'Authentication',
                    'password_hash_migrated',
                    'A user password hash was migrated to the current Astraea Argon2id policy.',
                    ['user_id' => (int)$user->ID]
                );
            }
        }

        return $user;
    }
}
