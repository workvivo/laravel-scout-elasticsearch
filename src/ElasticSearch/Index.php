<?php

namespace Matchish\ScoutElasticSearch\ElasticSearch;

use Illuminate\Support\Str;
use Matchish\ScoutElasticSearch\Searchable\ImportSource;

/**
 * @internal
 */
final class Index
{
    /**
     * @var array
     */
    private $aliases = [];

    /**
     * @var string
     */
    private $name;
    /**
     * @var array|null
     */
    private $settings;
    /**
     * @var array|null
     */
    private $mappings;

    /**
     * Index constructor.
     *
     * @param  string  $name
     * @param  array  $settings
     * @param  array  $mappings
     */
    public function __construct(string $name, array $settings = null, array $mappings = null)
    {
        $this->name = $name;
        $this->settings = $settings;
        $this->mappings = $mappings;
    }

    /**
     * @return array
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @param  Alias  $alias
     */
    public function addAlias(Alias $alias): void
    {
        $this->aliases[$alias->name()] = $alias->config() ?: new \stdClass();
    }

    /**
     * @return array
     */
    public function config(): array
    {
        $config = [];
        if (! empty($this->settings)) {
            $config['settings'] = $this->settings;
        }
        if (! empty($this->mappings)) {
            $config['mappings'] = $this->mappings;
        }
        if (! empty($this->aliases())) {
            $config['aliases'] = $this->aliases();
        }

        return $config;
    }

    public static function fromSource(ImportSource $source): Index
    {
        // time() alone is 1-second resolution, so two overlapping runs of the
        // same model could mint the *identical* concrete index name — which
        // breaks the "I only ever delete my own frozen index" guarantee that
        // the rollback / clean-up safety relies on. The random suffix makes the
        // name unique per run while keeping the "{searchableAs}_" prefix that
        // removeUnpromotedIndex() and CleanUp depend on. Lower-cased because
        // Elasticsearch/OpenSearch index names must be lowercase.
        $name = $source->searchableAs().'_'.time().'_'.Str::lower(Str::random(6));
        $settingsConfigKey = "elasticsearch.indices.settings.{$source->searchableAs()}";
        $mappingsConfigKey = "elasticsearch.indices.mappings.{$source->searchableAs()}";
        $defaultSettings = [
            'number_of_shards' => 1,
            'number_of_replicas' => 0,

        ];
        $settings = config($settingsConfigKey, config('elasticsearch.indices.settings.default', $defaultSettings));
        $mappings = config($mappingsConfigKey, config('elasticsearch.indices.mappings.default'));

        return new static($name, $settings, $mappings);
    }
}
