<?php
declare(strict_types=1);

if (!defined('ABSPATH')) exit('VGT_ACCESS_DENIED');

/**
 * VISIONGAIATECHNOLOGY SYSTEM
 * MODULE: SCHEMA ENFORCEMENT (MU-Core)
 * ARCHITECT: OMEGA PROTOCOL
 */
final class VIS_Schema {
    public const XDR_SCHEMA_VERSION = '3';
    public const DOWNLOAD_SCHEMA_VERSION = '1';

    public static function enforce(): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || empty($wpdb->dbh)) {
            return;
        }

        if (!defined('VIS_VERSION')) {
            define('VIS_VERSION', '2.1.0-astraea');
        }
        if (!defined('VIS_TABLE_BANS')) {
            define('VIS_TABLE_BANS', 'vis_apex_bans');
        }
        if (!defined('VIS_TABLE_LOGS')) {
            define('VIS_TABLE_LOGS', 'vis_apex_logs');
        }
        if (!defined('VIS_VAULT_DIR')) {
            if (defined('WP_CONTENT_DIR')) {
                define('VIS_VAULT_DIR', WP_CONTENT_DIR . '/uploads/vis-vault-omega');
            } else {
                define('VIS_VAULT_DIR', dirname(__DIR__) . '/vault-storage');
            }
        }

        $installed_ver = function_exists('get_option') ? get_option('vis_db_version') : false;
        $xdr_ver = function_exists('get_option') ? get_option('vis_xdr_schema_version') : false;
        $download_ver = function_exists('get_option') ? get_option('vis_download_schema_version') : false;
        
        // Bail early if the database schema is already up-to-date
        if ($installed_ver === VIS_VERSION
            && $xdr_ver === self::XDR_SCHEMA_VERSION
            && $download_ver === self::DOWNLOAD_SCHEMA_VERSION) {
            return;
        }

        // 1. VAULT DIRECTORY ENFORCEMENT
        if (!is_dir(VIS_VAULT_DIR)) {
            if (function_exists('wp_mkdir_p')) {
                @wp_mkdir_p(VIS_VAULT_DIR);
            } else {
                @mkdir(VIS_VAULT_DIR, 0755, true);
            }
        }

        $vault_files = [
            'index.php' => "<?php\nhttp_response_code(404);\nexit;\n",
            '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder Deny,Allow\nDeny from all\n</IfModule>\nRemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n",
            'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization><directoryBrowse enabled="false" /></system.webServer></configuration>',
        ];

        if (is_dir(VIS_VAULT_DIR) && is_writable(VIS_VAULT_DIR)) {
            foreach ($vault_files as $name => $content) {
                $path = VIS_VAULT_DIR . DIRECTORY_SEPARATOR . $name;
                @file_put_contents($path, $content, LOCK_EX);
                @chmod($path, 0600);
            }
            @chmod(VIS_VAULT_DIR, 0700);
        }

        $charset_collate = $wpdb->get_charset_collate();
        
        // 2. DATABASE TABLE DEFINITIONS
        // [ DIAMANT VGT FIX ]: "0000-00-00 00:00:00" entfernt, um Fatal Errors auf Servern 
        // mit STRICT_TRANS_TABLES / NO_ZERO_DATE zu verhindern.
        
        $sql_bans = "CREATE TABLE " . $wpdb->prefix . VIS_TABLE_BANS . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ip varchar(45) NOT NULL,
            reason text NOT NULL,
            banned_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            request_uri varchar(255) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ip (ip),
            KEY banned_at (banned_at)
        ) $charset_collate;";
        
        $sql_logs = "CREATE TABLE " . $wpdb->prefix . VIS_TABLE_LOGS . " (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            module varchar(32) NOT NULL,
            type varchar(32) NOT NULL,
            message text NOT NULL,
            ip varchar(45) NOT NULL,
            severity tinyint(1) DEFAULT 1,
            PRIMARY KEY  (id),
            KEY module_timestamp (module, timestamp)
        ) $charset_collate;";
        
        $sql_oracle = "CREATE TABLE " . $wpdb->prefix . 'vis_oracle_patterns' . " (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            ip varchar(45) NOT NULL,
            type varchar(64) NOT NULL,
            message text NOT NULL,
            ai_reason text NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_rate_limits = "CREATE TABLE " . $wpdb->prefix . "vis_rate_limits (
            scope_hash char(64) NOT NULL,
            window_start bigint(20) unsigned NOT NULL,
            hits bigint(20) unsigned NOT NULL DEFAULT 1,
            expires_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (scope_hash),
            KEY expires_at (expires_at)
        ) $charset_collate;";

        $sql_xdr_events = "CREATE TABLE " . $wpdb->prefix . "vis_xdr_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_uuid char(32) NOT NULL,
            schema_version smallint(5) unsigned NOT NULL,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            sensor varchar(32) NOT NULL,
            category varchar(32) NOT NULL,
            event_type varchar(64) NOT NULL,
            role varchar(16) NOT NULL DEFAULT 'DETECTION',
            severity tinyint(3) unsigned NOT NULL,
            confidence tinyint(3) unsigned NOT NULL,
            score decimal(8,2) NOT NULL DEFAULT 0,
            attribution_confidence tinyint(3) unsigned NOT NULL DEFAULT 100,
            actor_ip varchar(45) NOT NULL DEFAULT '',
            actor_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            session_hash char(32) NOT NULL,
            entity_type varchar(32) NOT NULL,
            entity_id varchar(191) NOT NULL,
            request_id char(32) NOT NULL,
            correlation_id char(32) NOT NULL,
            execution_chain_id char(32) NOT NULL DEFAULT '',
            route varchar(191) NOT NULL,
            vector varchar(64) NOT NULL,
            action_type varchar(32) NOT NULL,
            outcome varchar(32) NOT NULL,
            privacy_class varchar(32) NOT NULL,
            causal_parent_id bigint(20) unsigned NULL,
            causal_edge varchar(32) NOT NULL DEFAULT 'SAME_REQUEST',
            metadata_json text NOT NULL,
            event_hash char(64) NOT NULL,
            dedupe_hash char(64) NOT NULL,
            occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY event_uuid (event_uuid),
            KEY last_seen (last_seen),
            KEY sensor_time (sensor,last_seen),
            KEY category_time (category,last_seen),
            KEY role_time (role,last_seen),
            KEY actor_time (actor_hash,last_seen),
            KEY entity_time (entity_type,entity_id,last_seen),
            KEY request_id (request_id),
            KEY correlation_id (correlation_id),
            KEY chain_id (execution_chain_id),
            KEY dedupe_time (dedupe_hash,last_seen)
        ) $charset_collate;";

        $sql_xdr_incidents = "CREATE TABLE " . $wpdb->prefix . "vis_xdr_incidents (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            incident_uuid char(32) NOT NULL,
            correlation_key char(64) NOT NULL,
            execution_chain_id char(32) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            closed_at datetime NULL,
            status varchar(24) NOT NULL,
            classification varchar(64) NOT NULL,
            severity tinyint(3) unsigned NOT NULL,
            confidence tinyint(3) unsigned NOT NULL,
            primary_actor varchar(191) NOT NULL,
            primary_entity_type varchar(32) NOT NULL,
            primary_entity_id varchar(191) NOT NULL,
            event_count bigint(20) unsigned NOT NULL DEFAULT 0,
            sensor_count smallint(5) unsigned NOT NULL DEFAULT 0,
            category_count smallint(5) unsigned NOT NULL DEFAULT 0,
            sensor_set text NOT NULL,
            category_set text NOT NULL,
            response_state varchar(32) NOT NULL,
            evidence_root char(64) NOT NULL DEFAULT '',
            attack_story longtext NOT NULL,
            related_entities text NOT NULL,
            resolution varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY incident_uuid (incident_uuid),
            KEY active_correlation (correlation_key,status,updated_at),
            KEY status_updated (status,updated_at),
            KEY classification (classification),
            KEY severity (severity)
        ) $charset_collate;";

        $sql_xdr_links = "CREATE TABLE " . $wpdb->prefix . "vis_xdr_incident_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            incident_uuid char(32) NOT NULL,
            event_id bigint(20) unsigned NOT NULL,
            linked_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY incident_event (incident_uuid,event_id),
            KEY event_id (event_id)
        ) $charset_collate;";

        $sql_xdr_responses = "CREATE TABLE " . $wpdb->prefix . "vis_xdr_responses (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            response_uuid char(32) NOT NULL,
            incident_uuid char(32) NOT NULL,
            owner varchar(32) NOT NULL DEFAULT 'TRINITY_XDR',
            action_type varchar(48) NOT NULL,
            target_type varchar(32) NOT NULL,
            target_id varchar(191) NOT NULL,
            reason_code varchar(64) NOT NULL,
            confidence tinyint(3) unsigned NOT NULL,
            authorized_by varchar(64) NOT NULL,
            started_at datetime NOT NULL,
            expires_at datetime NULL,
            status varchar(32) NOT NULL,
            rollback_json text NOT NULL,
            evidence_ref char(64) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY response_uuid (response_uuid),
            KEY incident_time (incident_uuid,started_at),
            KEY expiry_status (status,expires_at)
        ) $charset_collate;";

        $sql_xdr_evidence = "CREATE TABLE " . $wpdb->prefix . "vis_xdr_evidence (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            evidence_uuid char(32) NOT NULL,
            incident_uuid char(32) NOT NULL,
            event_uuid char(32) NOT NULL,
            evidence_type varchar(32) NOT NULL,
            sequence_num int(10) unsigned NOT NULL DEFAULT 0,
            previous_root char(64) NOT NULL DEFAULT '',
            current_root char(64) NOT NULL DEFAULT '',
            digest char(64) NOT NULL,
            created_at datetime NOT NULL,
            validity varchar(16) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY evidence_uuid (evidence_uuid),
            UNIQUE KEY uq_incident_seq (incident_uuid,sequence_num),
            KEY incident_evidence (incident_uuid,sequence_num)
        ) $charset_collate;";

        $sql_downloads = "CREATE TABLE " . $wpdb->prefix . "vis_secure_downloads (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(32) NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            display_name varchar(191) NOT NULL,
            storage_name char(64) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_size bigint(20) unsigned NOT NULL,
            file_hash char(64) NOT NULL,
            enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
            download_count bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            last_download_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            KEY enabled_created (enabled,created_at),
            KEY attachment_id (attachment_id)
        ) $charset_collate;";

        // 3. EXECUTE SCHEMA CREATION DIRECTLY (DEPENDENCY-FREE)
        $wpdb->suppress_errors();
        $tables = [
            $sql_oracle,
            $sql_bans,
            $sql_logs,
            $sql_rate_limits,
            $sql_xdr_events,
            $sql_xdr_incidents,
            $sql_xdr_links,
            $sql_xdr_responses,
            $sql_xdr_evidence,
            $sql_downloads,
        ];

        foreach ($tables as $table_sql) {
            $create_if_not_exists = preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', trim($table_sql));
            $wpdb->query($create_if_not_exists);
        }

        // Optional dbDelta if available or in admin context
        if (!function_exists('dbDelta') && function_exists('is_admin') && is_admin() && defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (function_exists('dbDelta')) {
            foreach ($tables as $table_sql) {
                dbDelta($table_sql);
            }
        }
        $wpdb->show_errors();
        
        // 4. FINALIZE UPDATE
        if (function_exists('update_option')) {
            update_option('vis_db_version', VIS_VERSION);
            update_option('vis_xdr_schema_version', self::XDR_SCHEMA_VERSION, false);
            update_option('vis_download_schema_version', self::DOWNLOAD_SCHEMA_VERSION, false);
            update_option('vgt_oracle_table_ready', true);
        }
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }
    }
}
