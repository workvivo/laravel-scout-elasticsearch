<?php

namespace Tests\Unit\ElasticSearch;

use App\Product;
use Matchish\ScoutElasticSearch\ElasticSearch\Index;
use Matchish\ScoutElasticSearch\Searchable\DefaultImportSourceFactory;
use Tests\TestCase;

class IndexTest extends TestCase
{
    public function test_creation_from_searchable()
    {
        $index = Index::fromSource(DefaultImportSourceFactory::from(Product::class));
        // Name is "{searchableAs}_{timestamp}_{random}": the timestamp is frozen
        // by the time() override below; the random suffix keeps two same-second
        // runs distinct. Must be lowercase (Elasticsearch index-name rule).
        $this->assertStringStartsWith('products_1525376494_', $index->name());
        $this->assertMatchesRegularExpression('/^products_1525376494_[a-z0-9]{6}$/', $index->name());
        $this->assertSame(strtolower($index->name()), $index->name());
    }

    public function test_two_indexes_from_the_same_source_get_distinct_names()
    {
        // time() is frozen below, so both names share a timestamp — only the
        // random suffix distinguishes them. This is what stops two overlapping
        // imports of the same model from minting the identical concrete index.
        $source = DefaultImportSourceFactory::from(Product::class);
        $this->assertNotEquals(
            Index::fromSource($source)->name(),
            Index::fromSource($source)->name()
        );
    }
}

namespace Matchish\ScoutElasticSearch\ElasticSearch;

function time(): int
{
    return 1525376494;
}
