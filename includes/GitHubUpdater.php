<?php

declare(strict_types=1);

namespace Dizzy\Reservations;

defined('ABSPATH') || exit;

final class GitHubUpdater
{
    private string $pluginBasename;
    private string $cacheKey;

    public function __construct(
        private string $pluginFile,
        private string $slug,
        private string $repository,
        private string $currentVersion
    ) {
        $this->pluginBasename = plugin_basename($pluginFile);
        $this->cacheKey = 'dizzy_github_release_' . md5($repository);
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);
        add_filter('plugins_api', [$this, 'pluginInformation'], 20, 3);
        add_filter('plugin_action_links_' . $this->pluginBasename, [$this, 'actionLinks']);
        add_action('admin_post_' . $this->checkAction(), [$this, 'checkNow']);
        add_action('admin_notices', [$this, 'checkNotice']);
        add_action('upgrader_process_complete', [$this, 'clearCacheAfterUpdate'], 10, 2);
    }

    /**
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public function actionLinks(array $links): array
    {
        if (! current_user_can('update_plugins')) {
            return $links;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . $this->checkAction()),
            $this->checkAction()
        );

        $links[] = '<a href="' . esc_url($url) . '">' .
            esc_html__('Check GitHub Updates Now', 'default') .
            '</a>';

        return $links;
    }

    public function checkNow(): void
    {
        if (! current_user_can('update_plugins')) {
            wp_die(esc_html__('You are not allowed to update plugins.', 'default'));
        }

        check_admin_referer($this->checkAction());

        delete_site_transient($this->cacheKey);
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        if (! function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        wp_update_plugins();

        wp_safe_redirect(
            add_query_arg(
                'dizzy_github_checked',
                rawurlencode($this->slug),
                admin_url('plugins.php')
            )
        );
        exit;
    }

    public function checkNotice(): void
    {
        if (
            ! current_user_can('update_plugins')
            || ! isset($_GET['dizzy_github_checked'])
            || sanitize_key(wp_unslash((string) $_GET['dizzy_github_checked'])) !== $this->slug
        ) {
            return;
        }
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('GitHub update check completed.', 'default'); ?></p>
        </div>
        <?php
    }

    public function checkForUpdate(mixed $transient): mixed
    {
        if (! is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->release();

        if ($release === null || version_compare($release['version'], $this->currentVersion, '<=')) {
            return $transient;
        }

        $transient->response[$this->pluginBasename] = (object) [
            'id' => 'github.com/' . $this->repository,
            'slug' => $this->slug,
            'plugin' => $this->pluginBasename,
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['package'],
            'requires_php' => '8.2',
        ];

        return $transient;
    }

    public function pluginInformation(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->slug) {
            return $result;
        }

        $release = $this->release();

        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => ucwords(str_replace('-', ' ', $this->slug)),
            'slug' => $this->slug,
            'version' => $release['version'],
            'author' => '<a href="https://poserinka.com">Poserinka Design</a>',
            'homepage' => $release['html_url'],
            'download_link' => $release['package'],
            'requires_php' => '8.2',
            'sections' => [
                'description' => esc_html__('Managed through GitHub Releases.', 'dizzy-reservations-manager'),
                'changelog' => wpautop(esc_html($release['body'])),
            ],
        ];
    }

    public function clearCacheAfterUpdate(mixed $upgrader, array $options): void
    {
        if (
            ($options['action'] ?? '') !== 'update'
            || ($options['type'] ?? '') !== 'plugin'
            || ! in_array($this->pluginBasename, (array) ($options['plugins'] ?? []), true)
        ) {
            return;
        }

        delete_site_transient($this->cacheKey);
    }

    private function checkAction(): string
    {
        return 'dizzy_check_github_update_' . sanitize_key($this->slug);
    }

    /**
     * @return array{version:string,html_url:string,package:string,body:string}|null
     */
    private function release(): ?array
    {
        $cached = get_site_transient($this->cacheKey);

        if (is_array($cached)) {
            return $cached['available'] ?? false ? $cached : null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . $this->repository . '/releases/latest',
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => $this->slug . '-wordpress-updater',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient($this->cacheKey, ['available' => false], HOUR_IN_SECONDS);
            return null;
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);

        if (! is_array($payload)) {
            set_site_transient($this->cacheKey, ['available' => false], HOUR_IN_SECONDS);
            return null;
        }

        $package = '';

        foreach ((array) ($payload['assets'] ?? []) as $asset) {
            if (
                is_array($asset)
                && ($asset['name'] ?? '') === $this->slug . '.zip'
                && isset($asset['browser_download_url'])
            ) {
                $package = esc_url_raw((string) $asset['browser_download_url']);
                break;
            }
        }

        $version = ltrim((string) ($payload['tag_name'] ?? ''), 'vV');

        if ($version === '' || $package === '') {
            set_site_transient($this->cacheKey, ['available' => false], HOUR_IN_SECONDS);
            return null;
        }

        $release = [
            'available' => true,
            'version' => $version,
            'html_url' => esc_url_raw((string) ($payload['html_url'] ?? '')),
            'package' => $package,
            'body' => (string) ($payload['body'] ?? ''),
        ];

        set_site_transient($this->cacheKey, $release, 6 * HOUR_IN_SECONDS);

        return $release;
    }
}
