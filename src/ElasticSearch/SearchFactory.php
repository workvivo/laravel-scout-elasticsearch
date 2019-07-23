<?php

namespace Matchish\ScoutElasticSearch\ElasticSearch;

use Laravel\Scout\Builder;
use ONGR\ElasticsearchDSL\Search;
use ONGR\ElasticsearchDSL\Sort\FieldSort;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermsQuery;
use ONGR\ElasticsearchDSL\Query\FullText\QueryStringQuery;

final class SearchFactory
{
    /**
     * @param Builder $builder
     * @param array $options
     * @return Search
     */
    public static function create(Builder $builder, array $options = []): Search
    {
        $search = new Search();
        $query = new QueryStringQuery($builder->query);
        if (!empty($builder->wheres)) {
            $boolQuery = new BoolQuery();
            foreach ($builder->wheres as $field => $value) {
                if (is_array($value)) {
                    $boolQuery->add(new TermsQuery((string) $field, $value), BoolQuery::SHOULD);
                    // $boolQuery->add($query, BoolQuery::SHOULD);
                } else {
                    $boolQuery->add(new TermQuery((string) $field, $value), BoolQuery::FILTER);
                    // $boolQuery->add($query, BoolQuery::MUST);
                }
            }
            $boolQuery->add($query, BoolQuery::MUST);
            $search->addQuery($boolQuery);
        } else {
            $search->addQuery($query);
        }
        if (array_key_exists('from', $options)) {
            $search->setFrom($options['from']);
        }
        if (array_key_exists('size', $options)) {
            $search->setSize($options['size']);
        }
        if (!empty($builder->orders)) {
            foreach ($builder->orders as $order) {
                $search->addSort(new FieldSort($order['column'], $order['direction']));
            }
        }

        return $search;
    }
}
