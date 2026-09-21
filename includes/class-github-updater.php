<?php
namespace ValkemediaDisableTrackbackSpam;

class GitHubUpdater
{
    /**
     * GitHub repository, bijvoorbeeld:
     * https://github.com/username/my-plugin
     */
    private string $repository;

    /**
     * Plugin basename:
     * my-plugin/my-plugin.php
     */
    private string $plugin_file;

    /**
     * Plugin slug:
     * my-plugin
     */
    private string $slug;

    /**
     * Huidige pluginversie.
     */
    private string $current_version;

    /**
     * URL naar de GitHub repository.
     */
    private string $repository_url;

    /**
     * Cache duur in seconden.
     */
    private int $cache_time = 43200; // 12 uur

    /**
     * Constructor.
     *
     * @param string $repository GitHub repository, bijvoorbeeld username/repository
     * @param string $plugin_file Plugin basename
     * @param string $current_version Huidige pluginversie
     * @param string|null $slug Plugin slug
     */
    public function __construct(
        string $repository,
        string $plugin_file,
        string $current_version,
        ?string $slug = null
    ) {
        $this->repository = trim($repository, '/');
        $this->plugin_file = $plugin_file;
        $this->current_version = ltrim(trim($current_version), 'v');

        $this->slug = $slug ?: dirname($plugin_file);

        $this->repository_url = 'https://github.com/' . $this->repository;

        /*
         * WordPress moet weten dat dit geen WordPress.org-plugin is.
         */
        add_filter(
            'site_transient_update_plugins',
            [$this, 'check_for_update']
        );

        /*
         * Informatie tonen wanneer iemand op
         * "Bekijk versie X" klikt.
         */
        add_filter(
            'plugins_api',
            [$this, 'plugin_information'],
            20,
            3
        );

        /*
         * Voeg onze eigen cache-clearing mogelijkheid toe.
         */
        add_action(
            'upgrader_process_complete',
            [$this, 'clear_cache_after_update'],
            10,
            2
        );
    }

    /**
     * Controleer GitHub op een nieuwe release.
     *
     * @param object $transient
     *
     * @return object
     */
    public function check_for_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        /*
         * WordPress kan dit filter aanroepen voordat checked
         * beschikbaar is.
         */
        if (empty($transient->checked)) {
            return $transient;
        }

        /*
         * Als onze plugin niet in de lijst staat,
         * hoeven we niets te doen.
         */
        if (!array_key_exists($this->plugin_file, $transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $transient;
        }

        $latest_version = $release['version'];

        /*
         * Alleen een update aanbieden als de GitHub-versie
         * daadwerkelijk nieuwer is.
         */
        if (version_compare(
            $latest_version,
            $this->current_version,
            '>'
        )) {
            $update = new \stdClass();

            $update->slug = $this->slug;
            $update->plugin = $this->plugin_file;

            $update->new_version = $latest_version;
            $update->url = $release['html_url'];

            /*
             * Dit is de daadwerkelijke ZIP die WordPress
             * moet downloaden.
             */
            $update->package = $release['package'];

            /*
             * Optionele metadata.
             */
            $update->tested = $this->get_tested_version();
            $update->requires = $this->get_requires_version();
            $update->requires_php = $this->get_requires_php();

            $transient->response[$this->plugin_file] = $update;
        }

        return $transient;
    }

    /**
     * Geef plugininformatie terug voor de WordPress plugin
     * information popup.
     *
     * @param false|object $result
     * @param string $action
     * @param object $args
     *
     * @return false|object
     */
    public function plugin_information($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        $info = new \stdClass();

        $info->name = $this->get_plugin_name();
        $info->slug = $this->slug;
        $info->version = $release['version'];

        $info->author = $this->get_plugin_author();
        $info->homepage = $this->repository_url;

        $info->download_link = $release['package'];

        $info->last_updated = $release['published_at'];

        $info->sections = [
            'description' => $this->get_plugin_description(),

            'changelog' => $this->format_changelog(
                $release['body']
            ),
        ];

        $info->banners = [
            'low' => '',
            'high' => '',
        ];

        return $info;
    }

    /**
     * Haal de laatste GitHub release op.
     *
     * @return array|null
     */
    private function get_latest_release(): ?array
    {
        $cache_key = $this->get_cache_key();

        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $this->repository
        );

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 10,

                'headers' => [
                    'Accept' => 'application/vnd.github+json',

                    /*
                     * GitHub verwacht een User-Agent.
                     */
                    'User-Agent' => 'WordPress/' . get_bloginfo('url'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if (!$body) {
            return null;
        }

        $data = json_decode($body, true);

        if (
            !is_array($data) ||
            empty($data['tag_name'])
        ) {
            return null;
        }

        /*
         * GitHub tag:
         *
         * v1.2.3
         *
         * wordt:
         *
         * 1.2.3
         */
        $version = ltrim(
            trim($data['tag_name']),
            'v'
        );

        /*
         * Zoek de ZIP tussen de release assets.
         */
        $package = $this->find_release_asset($data);

        /*
         * Als er geen eigen ZIP-release asset is,
         * gebruik de GitHub zipball als fallback.
         */
        if (!$package && !empty($data['zipball_url'])) {
            $package = $data['zipball_url'];
        }

        if (!$package) {
            return null;
        }

        $release = [
            'version' => $version,

            'tag_name' => $data['tag_name'],

            'html_url' => $data['html_url'] ?? $this->repository_url,

            'package' => $package,

            'body' => $data['body'] ?? '',

            'published_at' => $data['published_at'] ?? '',
        ];

        /*
         * Cache de release.
         */
        set_transient(
            $cache_key,
            $release,
            $this->cache_time
        );

        return $release;
    }

    /**
     * Zoek een ZIP tussen de GitHub release-assets.
     * Bijvoorbeeld:
     * my-plugin-1.2.3.zip
     *
     * @param array $release
     *
     * @return string|null
     */
    private function find_release_asset(array $release): ?string
    {
        if (
            empty($release['assets']) ||
            !is_array($release['assets'])
        ) {
            return null;
        }

        $assets = $release['assets'];

        /*
         * Eerst zoeken naar een ZIP waarin de versie voorkomt.
         */
        foreach ($assets as $asset) {
            if (
                empty($asset['browser_download_url']) ||
                empty($asset['name'])
            ) {
                continue;
            }

            $name = strtolower($asset['name']);

            if (substr($name, -4) === '.zip' && strpos($name, strtolower($release['tag_name']))) {
                return $asset['browser_download_url'];
            }
        }

        /*
         * Anders de eerste ZIP gebruiken.
         */
        foreach ($assets as $asset) {
            if (
                empty($asset['browser_download_url']) ||
                empty($asset['name'])
            ) {
                continue;
            }

            if (substr(strtolower($asset['name']), -4) === '.zip') {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    /**
     * Cache key.
     */
    private function get_cache_key(): string
    {
        return 'github_updater_' . md5(
                $this->repository
            );
    }

    /**
     * Cache verwijderen nadat WordPress een update
     * heeft uitgevoerd.
     *
     * @param WP_Upgrader $upgrader
     * @param array $options
     */
    public function clear_cache_after_update($upgrader, $options): void
    {
        if (
            empty($options['action']) ||
            $options['action'] !== 'update'
        ) {
            return;
        }

        if (
            empty($options['type']) ||
            $options['type'] !== 'plugin'
        ) {
            return;
        }

        delete_transient(
            $this->get_cache_key()
        );
    }

    /**
     * Pluginnaam uit de plugin header.
     */
    private function get_plugin_name(): string
    {
        $data = $this->get_plugin_data();

        return $data['Name'] ?: $this->slug;
    }

    /**
     * Auteur.
     */
    private function get_plugin_author(): string
    {
        $data = $this->get_plugin_data();

        return $data['Author'] ?? '';
    }

    /**
     * Beschrijving.
     */
    private function get_plugin_description(): string
    {
        $data = $this->get_plugin_data();

        return $data['Description'] ?? '';
    }

    /**
     * WordPress-versie waarvoor getest is.
     */
    private function get_tested_version(): string
    {
        $data = $this->get_plugin_data();

        return $data['Tested WP'] ?? '';
    }

    /**
     * Minimale WordPress-versie.
     */
    private function get_requires_version(): string
    {
        $data = $this->get_plugin_data();

        return $data['RequiresWP'] ?? '';
    }

    /**
     * Minimale PHP-versie.
     */
    private function get_requires_php(): string
    {
        $data = $this->get_plugin_data();

        return $data['RequiresPHP'] ?? '';
    }

    /**
     * Plugin header uitlezen.
     */
    private function get_plugin_data(): array
    {
        static $data = null;

        if ($data !== null) {
            return $data;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $this->plugin_file;

        if (!file_exists($plugin_path)) {
            return [];
        }

        $data = get_plugin_data(
            $plugin_path,
            false,
            false
        );

        return $data;
    }

    /**
     * GitHub Markdown changelog omzetten naar simpele HTML.
     */
    private function format_changelog(string $body): string
    {
        if (!$body) {
            return '<p>Geen changelog beschikbaar.</p>';
        }

        /*
         * wpautop zorgt in ieder geval voor nette paragrafen.
         *
         * We voeren hier bewust geen willekeurige Markdown
         * rechtstreeks als HTML uit.
         */

        return wpautop(
            esc_html($body)
        );
    }
}