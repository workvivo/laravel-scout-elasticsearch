<?php

namespace Tests;

use App\Product;

/**
 * Class IntegrationTestCase.
 *
 * Index cleanup (snapshot + diff) lives in the parent TestCase, so any test
 * that ends up touching the cluster is covered regardless of inheritance.
 * This subclass only adds the integration-specific mapping config.
 */
class IntegrationTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('elasticsearch', require(__DIR__.'/../config/elasticsearch.php'));
        $app['config']->set('elasticsearch.indices.mappings.products', [
            'properties' => [
                'type' => [
                    'type' => 'keyword',
                ],
                'price' => [
                    'type' => 'integer',
                ],
            ],
        ]);
        Product::preventAccessingMissingAttributes();
    }
}
