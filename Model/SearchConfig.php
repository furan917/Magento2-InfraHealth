<?php
declare(strict_types=1);

namespace Furan\InfraHealth\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Search connection for OpenSearch, Elasticsearch 7/8 or ElasticSuite.
 */
class SearchConfig
{
    private const ELASTICSUITE_CLIENT = 'smile_elasticsuite_core_base_settings/es_client/';

    public function __construct(private ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * @return array{engine: string, engine_label: string, servers: string[], host: string, port: int,
     *     prefix: string, username: string, password: string, verify_tls: bool}
     */
    public function get(): array
    {
        $engine = (string)($this->scopeConfig->getValue('catalog/search/engine') ?: 'opensearch');
        return $engine === 'elasticsuite' ? $this->elasticsuite() : $this->core($engine);
    }

    private function core(string $engine): array
    {
        $value = fn (string $key) => (string)$this->scopeConfig->getValue("catalog/search/{$engine}_{$key}");
        $auth = (bool)$value('enable_auth');
        [$url, $host, $port] = $this->endpoint(
            $value('server_hostname') ?: 'localhost',
            (int)($value('server_port') ?: 9200),
            false
        );

        return [
            'engine' => $engine,
            'engine_label' => str_starts_with($engine, 'elasticsearch') ? 'Elasticsearch' : 'OpenSearch',
            'servers' => [$url],
            'host' => $host,
            'port' => $port,
            'prefix' => $value('index_prefix') ?: 'magento2',
            'username' => $auth ? $value('username') : '',
            'password' => $auth ? $value('password') : '',
            'verify_tls' => true,
        ];
    }

    private function elasticsuite(): array
    {
        $value = fn (string $key) => (string)$this->scopeConfig->getValue(self::ELASTICSUITE_CLIENT . $key);
        $https = (bool)$value('enable_https_mode');
        $auth = (bool)$value('enable_http_auth');

        $servers = [];
        $first = null;
        foreach (array_filter(array_map('trim', explode(',', $value('servers') ?: 'localhost:9200'))) as $server) {
            $port = 9200;
            if (preg_match('/^(.*):(\d+)$/', $server, $m)) {
                [$server, $port] = [$m[1], (int)$m[2]];
            }
            $endpoint = $this->endpoint($server, $port, $https);
            $first ??= $endpoint;
            $servers[] = $endpoint[0];
        }
        $first ??= $this->endpoint('localhost', 9200, $https);

        return [
            'engine' => 'elasticsuite',
            'engine_label' => 'ElasticSuite',
            'servers' => $servers ?: [$first[0]],
            'host' => $first[1],
            'port' => $first[2],
            'prefix' => (string)($this->scopeConfig->getValue('smile_elasticsuite_core_base_settings/indices_settings/alias')
                ?: 'magento2'),
            'username' => $auth ? $value('http_auth_user') : '',
            'password' => $auth ? $value('http_auth_pwd') : '',
            'verify_tls' => $value('enable_certificate_validation') !== '0',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: int} Base URL, host, port
     */
    private function endpoint(string $host, int $port, bool $https): array
    {
        $scheme = $https ? 'https' : 'http';
        if (preg_match('#^(https?)://#i', $host, $m)) {
            $scheme = strtolower($m[1]);
            $host = substr($host, strlen($m[0]));
        }
        $host = rtrim($host, '/');
        $urlHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
        return [sprintf('%s://%s:%d', $scheme, $urlHost, $port), $host, $port];
    }
}
