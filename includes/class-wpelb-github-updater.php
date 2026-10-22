<?php
/**
 * Native WordPress update integration for tagged, published GitHub Releases.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_GitHub_Updater {
    const UPDATE_URI    = 'https://github.com/lstamellos/WP-etherpad-to-liveblog';
    const API_URL       = 'https://api.github.com/repos/lstamellos/WP-etherpad-to-liveblog/releases/latest';
    const SLUG          = 'wp-etherpad-to-liveblog';
    const ZIP_NAME      = 'wp-etherpad-to-liveblog.zip';
    const CACHE_KEY     = 'wpelb_github_latest_release';

    /**
     * Allow WordPress core to discover this plugin's updates and details.
     */
    public static function register() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
    }

    /**
     * Handle ONLY our plugin's Update URI; never affect other GitHub-hosted plugins.
     *
     * @param array|false $update     Existing update response.
     * @param array       $plugin_data Installed plugin headers.
     * @param string      $plugin_file Relative plugin file.
     * @param array       $locales    Requested locales.
     * @return array|false
     */
    public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
        if (
            plugin_basename( WPELB_PLUGIN_FILE ) !== $plugin_file ||
            ! isset( $plugin_data['UpdateURI'], $plugin_data['Version'] ) ||
            self::UPDATE_URI !== $plugin_data['UpdateURI']
        ) {
            return $update;
        }

        $release = self::latest_release();
        if ( ! $release ) {
            return false;
        }

        if ( ! version_compare( $release['version'], $plugin_data['Version'], '>' ) ) {
            return false;
        }

        return array(
            'id'           => self::UPDATE_URI,
            'slug'         => self::SLUG,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '6.4',
            'requires_php' => '8.0',
            // Do not set autoupdate: retain the administrator's per-plugin WP setting.
        );
    }

    /**
     * Populate the normal WordPress "View details" dialog.
     *
     * @param mixed  $result Core/other plugin result.
     * @param string $action API action.
     * @param object $args   API arguments.
     * @return mixed
     */
    public static function plugin_information( $result, $action, $args ) {
        if (
            'plugin_information' !== $action ||
            ! is_object( $args ) ||
            ! isset( $args->slug ) ||
            self::SLUG !== $args->slug
        ) {
            return $result;
        }

        $release = self::latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'WP Etherpad to Liveblog',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => 'OmniaTV contributors',
            'homepage'      => self::UPDATE_URI,
            'requires'      => '6.4',
            'requires_php'  => '8.0',
            'last_updated'  => $release['published_at'],
            'download_link' => $release['package'],
            'sections'      => array(
                'description' => '<p>Connect Etherpad reporting to Automattic Liveblog in WordPress.</p>',
                'changelog'   => wpautop( esc_html( $release['notes'] ) ),
            ),
        );
    }

    /**
     * Fetch and cache validated release metadata. No GitHub token needed for this
     * public repository; failures fail closed and never offer a source ZIP.
     *
     * @return array|false
     */
    private static function latest_release() {
        $cached = get_site_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            // A negative result is cached too, to avoid hammering GitHub.
            return isset( $cached['release'] ) ? $cached['release'] : false;
        }

        $response = wp_safe_remote_get(
            self::API_URL,
            array(
                'timeout'             => 8,
                'redirection'         => 0,
                'limit_response_size' => 262144,
                'headers'             => array(
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'WP-Etherpad-to-Liveblog/' . WPELB_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_site_transient( self::CACHE_KEY, array( 'release' => false ), 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $release = self::validate_release( $data );
        // A published release may briefly be missing its asset while the
        // GitHub Actions packaging workflow finishes. Retry fairly soon.
        set_site_transient(
            self::CACHE_KEY,
            array( 'release' => $release ),
            $release ? 15 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS
        );

        return $release;
    }

    /**
     * Reject prereleases, invalid versions and missing/foreign ZIP packages.
     * Never fall back to GitHub-generated source archives: their root folder
     * differs from the installed WordPress plugin directory.
     *
     * @param mixed $data Decoded GitHub response.
     * @return array|false
     */
    private static function validate_release( $data ) {
        if (
            ! is_array( $data ) ||
            ! empty( $data['draft'] ) ||
            ! empty( $data['prerelease'] ) ||
            ! isset( $data['tag_name'], $data['assets'] ) ||
            ! is_string( $data['tag_name'] ) ||
            ! is_array( $data['assets'] ) ||
            ! preg_match( '/^v?([0-9]+\\.[0-9]+\\.[0-9]+)$/D', $data['tag_name'], $matches )
        ) {
            return false;
        }

        $tag = $data['tag_name'];
        $package = self::UPDATE_URI . '/releases/download/' . rawurlencode( $tag ) . '/' . self::ZIP_NAME;

        foreach ( $data['assets'] as $asset ) {
            if (
                ! is_array( $asset ) ||
                ( $asset['name'] ?? null ) !== self::ZIP_NAME ||
                ( $asset['state'] ?? null ) !== 'uploaded' ||
                ! isset( $asset['browser_download_url'] ) ||
                $asset['browser_download_url'] !== $package ||
                (int) ( $asset['size'] ?? 0 ) < 100
            ) {
                continue;
            }

            return array(
                'version'      => $matches[1],
                'url'          => self::UPDATE_URI . '/releases/tag/' . rawurlencode( $tag ),
                'package'      => $package,
                'published_at' => isset( $data['published_at'] ) && is_string( $data['published_at'] ) ? $data['published_at'] : '',
                'notes'        => isset( $data['body'] ) && is_string( $data['body'] ) ? substr( $data['body'], 0, 20000 ) : '',
            );
        }

        return false;
    }
}
