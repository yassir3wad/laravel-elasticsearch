<?php

declare(strict_types=1);

namespace PDPhilip\Elasticsearch\Query;


use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PDPhilip\Elasticsearch\Enums\WaitFor;
use PDPhilip\Elasticsearch\Exceptions\ParameterException;
use PDPhilip\Elasticsearch\Helpers\Helpers;
use PDPhilip\Elasticsearch\Helpers\ParameterBuilder;

class Grammar extends BaseGrammar
{
  /**
   * The index suffix.
   *
   * @var string
   */
  protected $indexSuffix = '';

  protected static $filter;

  protected static $functionScore;

  /**
   * Compile the given values to an Elasticsearch insert statement
   *
   * @param  Builder  $builder
   * @param  array  $values
   * @return array
   */
  public function compileInsert(Builder $builder, array $values): array
  {
    $params = [];

    if (! is_array(reset($values))) {
      $values = [$values];
    }

    foreach ($values as $doc) {
      $doc['id'] = $doc['id'] ?? ((string) Str::orderedUuid());

      $index = [
        '_index' => $builder->from . $this->indexSuffix,
        '_id'    => $doc['id'],
      ];

      $params['body'][] = ['index' => $index];

      foreach($doc as &$property) {
        $property = $this->getStringValue($property);
      }

      $params['body'][] = $doc;
    }

    $options = $builder->compileOptions();
    $options['refresh'] = $options['refresh']->get();

    return [
      ...$params,
      ...$options
    ];
  }

  /**
   * Compile a delete query
   *
   * @param  Builder  $builder
   * @return array
   */
  public function compileDelete(Builder $builder): array
  {

    $wheres = $builder->compileWheres();

    if (isset($wheres['_id'])) {
      return [
        'index' => $builder->from,
        'id' => $wheres['_id'],
        'refresh' => $builder->getOption('waitForRefresh', WaitFor::WAITFOR)->get()
      ];
    }

    $options = $builder->compileOptions();
    $options['refresh'] = $options['refresh']->getOperation();
    $params = $this->buildParams($builder->from, $wheres, $options);

    return $params;
  }



  /**
   * @throws ParameterException
   * @throws QueryException
   */
  public function buildSearchParams($index, $searchQuery, $searchOptions, $wheres = [], $options = [], $fields = [], $columns = []): array
  {
    $searchOptions = $this->clearAndStashMeta($searchOptions);
    $options = $this->clearAndStashMeta($options);
    $params = [];
    if ($index) {
      $params['index'] = $index;
    }
    $params['body'] = [];
    $queryString['query'] = $searchQuery;
    if ($fields) {
      $queryString['fields'] = [];
      foreach ($fields as $field => $boostLevel) {
        if ($boostLevel > 1) {
          $field = $field.'^'.$boostLevel;
        }
        $queryString['fields'][] = $field;
      }
      if (count($queryString['fields']) > 1) {
        $queryString['type'] = 'cross_fields';
      }

    }
    if (! empty($searchOptions['highlight'])) {
      $params['body']['highlight'] = $searchOptions['highlight'];
      unset($searchOptions['highlight']);
    }

    if ($searchOptions) {
      foreach ($searchOptions as $searchOption => $searchOptionValue) {
        $queryString[$searchOption] = $searchOptionValue;
      }
    }
    $wheres = $this->addSearchToWheres($wheres, $queryString);
    $dsl = $this->buildQuery($wheres);

    $params['body']['query'] = $dsl['query'];

    if ($columns && $columns != ['*']) {
      $params['body']['_source'] = $columns;
    }
    if ($options) {
      $opts = $this->buildOptions($options);
      if ($opts) {
        foreach ($opts as $key => $value) {
          if (isset($params[$key])) {
            $params[$key] = array_merge($params[$key], $value);
          } else {
            $params[$key] = $value;
          }
        }
      }
    }
    if (self::$filter) {
      $params = $this->parseFilterParameter($params, self::$filter);
      self::$filter = [];
    }
    if (self::$functionScore) {
      $params = $this->parseFunctionScore($params, self::$functionScore);
      self::$functionScore = [];
    }

    return $params;
  }

  /**
   * @throws ParameterException
   * @throws QueryException
   */
  public function buildParams($index, $wheres, $options = [], $columns = [], $_id = null): array
  {
    $options = $this->clearAndStashMeta($options);
    if ($index) {
      $params = [
        'index' => $index,
      ];
    }

    if ($_id) {
      $params['id'] = $_id;
    }

    $params['body'] = $this->buildQuery($wheres);
    if ($columns && $columns != '*') {
      $params['body']['_source'] = $columns;
    }
    $opts = $this->buildOptions($options);
    if ($opts) {
      foreach ($opts as $key => $value) {
        if (isset($params[$key])) {
          $params[$key] = array_merge($params[$key], $opts[$key]);
        } else {
          $params[$key] = $value;
        }
      }
    }
    if (self::$filter) {
      $params = $this->parseFilterParameter($params, self::$filter);
      self::$filter = [];
    }
    if (self::$functionScore) {
      $params = $this->parseFunctionScore($params, self::$functionScore);
      self::$functionScore = [];
    }

    return $params;
  }

  public function createNestedAggs($columns, $sort): array
  {
    $aggs = [];
    $terms = [
      'terms' => [
        'field' => $columns[0],
        'size' => 10000,
      ],
    ];
    if (isset($sort['_count'])) {
      $terms['terms']['order'] = [];
      if ($sort['_count'] == 'asc') {
        $terms['terms']['order'][] = ['_count' => 'asc'];
      } else {
        $terms['terms']['order'][] = ['_count' => 'desc'];
      }
    }
    if (isset($sort[$columns[0]])) {
      if ($sort[$columns[0]] == 'asc') {
        $terms['terms']['order'][] = ['_key' => 'asc'];
      } else {
        $terms['terms']['order'][] = ['_key' => 'desc'];
      }
    }
    $aggs['by_'.$columns[0]] = $terms;
    if (count($columns) > 1) {
      $aggs['by_'.$columns[0]]['aggs'] = $this->createNestedAggs(array_slice($columns, 1), $sort);
    }

    return $aggs;
  }

  public function addSearchToWheres($wheres, $queryString): array
  {
    $clause = ['_' => ['search' => $queryString]];
    if (! $wheres) {
      return $clause;
    }
    if (! empty($wheres['and'])) {
      $wheres['and'][] = $clause;

      return $wheres;
    }
    if (! empty($wheres['or'])) {
      $newOrs = [];
      foreach ($wheres['or'] as $cond) {
        $cond['and'][] = $clause;
        $newOrs[] = $cond;
      }
      $wheres['or'] = $newOrs;

      return $wheres;
    }

    return ['and' => [$wheres, $clause]];
  }

  /**
   * @throws ParameterException
   * @throws QueryException
   */
  private function buildQuery($wheres): array
  {
    if (! $wheres) {
      return ParameterBuilder::matchAll();
    }

    $dsl = $this->convertWheresToDSL($wheres);

    return ParameterBuilder::query($dsl);
  }

  private function clearAndStashMeta($options): array
  {
    if (! empty($options['_meta'])) {
      $this->stashMeta($options['_meta']);
      unset($options['_meta']);
    }

    return $options;
  }

  /**
   * @throws ParameterException
   * @throws QueryException
   */
  public function convertWheresToDSL($wheres, $parentField = false): array
  {
    $dsl = ['bool' => []];
    foreach ($wheres as $logicalOperator => $conditions) {
      switch ($logicalOperator) {
        case 'and':
          $dsl['bool']['must'] = [];
          foreach ($conditions as $condition) {
            $parsedCondition = $this->parseCondition($condition, $parentField);
            if (! empty($parsedCondition)) {
              $dsl['bool']['must'][] = $parsedCondition;
            }
          }
          break;
        case 'or':
          $dsl['bool']['should'] = [];
          foreach ($conditions as $conditionGroup) {
            $boolClause = ['bool' => ['must' => []]];
            foreach ($conditionGroup as $subConditions) {
              foreach ($subConditions as $subCondition) {
                $parsedCondition = $this->parseCondition($subCondition, $parentField);
                if (! empty($parsedCondition)) {
                  $boolClause['bool']['must'][] = $parsedCondition;
                }
              }
            }
            if (! empty($boolClause['bool']['must'])) {
              $dsl['bool']['should'][] = $boolClause;
            }
          }
          break;
        default:
          return $this->parseCondition($wheres, $parentField);
      }
    }

    return $dsl;
  }

  /**
   * @throws ParameterException
   * @throws QueryException
   */
  private function parseCondition($condition, $parentField = null): array
  {
    $field = key($condition);
    if ($parentField) {
      if (! str_starts_with($field, $parentField.'.')) {
        $field = $parentField.'.'.$field;
      }
    }

    if ($field == 'multi_match') {
      return $this->buildMultiMatch($condition['multi_match']);
    }

    $value = current($condition);

    if (! is_array($value)) {

      return ['match' => [$field => $value]];
    } else {
      $operator = key($value);
      $operand = current($value);
      $queryPart = [];

      switch ($operator) {
        case 'lt':
          $queryPart = ['range' => [$field => ['lt' => $operand]]];
          break;
        case 'lte':
          $queryPart = ['range' => [$field => ['lte' => $operand]]];
          break;
        case 'gt':
          $queryPart = ['range' => [$field => ['gt' => $operand]]];
          break;
        case 'gte':
          $queryPart = ['range' => [$field => ['gte' => $operand]]];
          break;
        case 'search':
          $queryPart = ['query_string' => $operand];
          break;
        case 'like':
          $queryPart = [
            'query_string' => [
              'query' => $field.':*'.Helpers::escape($operand).'*',
            ],
          ];
          break;
        case 'not_like':
          $queryPart = [
            'query_string' => [
              'query' => '(NOT '.$field.':*'.Helpers::escape($operand).'*)',
            ],
          ];
          break;
        case 'regex':
          $queryPart = ['regexp' => [$field => ['value' => $operand]]];
          break;
        case 'exists':
          $queryPart = ['exists' => ['field' => $field]];
          break;
        case 'not_exists':
          $queryPart = ['bool' => ['must_not' => [['exists' => ['field' => $field]]]]];
          break;
        case 'ne':
          $queryPart = ['bool' => ['must_not' => [['match' => [$field => $operand]]]]];
          break;
        case 'in':
          if ($this->connection->getBypassMapValidation()) {
            $queryPart = ['terms' => [$field => $operand]];
          } else {
            $keywordField = $this->parseRequiredKeywordMapping($field);
            if (! $keywordField) {
              $queryPart = ['terms' => [$field => $operand]];
            } else {
              $queryPart = ['terms' => [$keywordField => $operand]];
            }
          }

          break;
        case 'nin':
          if ($this->connection->getBypassMapValidation()) {
            $queryPart = ['bool' => ['must_not' => ['terms' => [$field => $operand]]]];
          } else {
            $keywordField = $this->parseRequiredKeywordMapping($field);
            if (! $keywordField) {
              $queryPart = ['bool' => ['must_not' => ['terms' => [$field => $operand]]]];
            } else {
              $queryPart = ['bool' => ['must_not' => ['terms' => [$keywordField => $operand]]]];
            }
          }

          break;
        case 'between':
          $queryPart = ['range' => [$field => ['gte' => $operand[0], 'lte' => $operand[1]]]];
          break;
        case 'not_between':
          $queryPart = ['bool' => ['must_not' => ['range' => [$field => ['gte' => $operand[0], 'lte' => $operand[1]]]]]];
          break;
        case 'phrase':
          $queryPart = ['match_phrase' => [$field => $operand]];
          break;
        case 'phrase_prefix':
          $queryPart = ['match_phrase_prefix' => [$field => ['query' => $operand]]];
          break;
        case 'exact':

          if ($this->connection->getBypassMapValidation()) {
            $keywordField = $field;
          } else {
            $keywordField = $this->parseRequiredKeywordMapping($field);
            if (! $keywordField) {
              throw new ParameterException('Field ['.$field.'] is not a keyword field which is required for the [exact] operator.');
            }
          }

          $queryPart = ['term' => [$keywordField => $operand]];
          break;
        case 'group':
          $must = $field;
          $queryPart = ['bool' => [$must => $this->convertWheresToDSL($operand['wheres'])]];
          break;
        case 'nested':
          $queryPart = [
            'nested' => [
              'path' => $field,
              'query' => $this->convertWheresToDSL($operand['wheres'], $field),
              'score_mode' => $operand['score_mode'],
            ],
          ];
          break;
        case 'not_nested':
          $queryPart = [
            'bool' => [
              'must_not' => [
                [
                  'nested' => [
                    'path' => $field,
                    'query' => $this->convertWheresToDSL($operand['wheres']),
                    'score_mode' => $operand['score_mode'],
                  ],
                ],
              ],
            ],

          ];
          break;
        case 'innerNested':
          $options = $this->buildNestedOptions($operand['options'], $field);
          if (! $options) {
            $options['size'] = 100;
          }
          $query = ParameterBuilder::matchAll()['query'];
          if (! empty($operand['wheres'])) {
            $query = $this->convertWheresToDSL($operand['wheres'], $field);
          }
          $queryPart = [
            'nested' => [
              'path' => $field,
              'query' => $query,
              'inner_hits' => $options,
            ],
          ];

          break;
        default:
          abort(400, 'Invalid operator ['.$operator.'] provided for condition.');
      }

      return $queryPart;
    }
  }

  private function buildMultiMatch($payload): array
  {
    $query = [
      'multi_match' => [
        'query' => $payload['query'],
        'type' => $payload['type'],
        'fields' => $payload['fields'],
      ],
    ];
    if (! empty($payload['options'])) {
      $query['multi_match'] = array_merge($query['multi_match'], $payload['options']);
    }

    return $query;
  }

  /**
   * @throws ParameterException
   */
  private function buildOptions($options): array
  {
    $return = [];
    if ($options) {
      foreach ($options as $key => $value) {
        switch ($key) {
          case 'prev_search_after':
            $return['_meta']['prev_search_after'] = $value;
            break;
          case 'search_after':
            $return['body']['search_after'] = $value;
            break;
          case 'limit':
            $return['size'] = $value;
            break;
          case 'sort':
            if (! isset($return['body']['sort'])) {
              $return['body']['sort'] = [];
            }
            foreach ($value as $field => $sortPayload) {
              $sort = ParameterBuilder::fieldSort($field, $sortPayload, $this->connection->getAllowIdSort());
              if ($sort) {
                $return['body']['sort'][] = $sort;
              }
            }
            break;
          case 'skip':
            $return['from'] = $value;
            break;
          case 'minScore':
            $return['body']['min_score'] = $value;
            break;
          case 'filters':
            foreach ($value as $filterType => $filerValues) {
              $this->parseFilter($filterType, $filerValues);
            }
            break;
          case 'highlights':
            $return['body']['highlight'] = $value;
            break;

          case 'random_score':
            self::$functionScore = [
              'random_score' => [
                'field' => $value['column'],
                'seed' => $value['seed'],
              ],
            ];

            break;
          case 'refresh':
            $return['refresh'] = $value;

            break;
          case 'multiple':
          case 'searchOptions':

            //Pass through
            break;
          default:
            throw new ParameterException('Unexpected option: '.$key);
        }
      }
    }

    return $return;
  }

  /**
   * @throws ParameterException
   */
  private function buildNestedOptions($options, $field): array
  {
    $options = $this->buildOptions($options);
    if (! empty($options['body'])) {
      $body = $options['body'];
      unset($options['body']);
      $options = array_merge($options, $body);
    }
    if (! empty($options['sort'])) {
      //ensure that the sort field is prefixed with the nested field
      $sorts = [];
      foreach ($options['sort'] as $sort) {
        foreach ($sort as $sortField => $sortPayload) {
          if (! str_starts_with($sortField, $field.'.')) {
            $sortField = $field.'.'.$sortField;
          }
          $sorts[] = [$sortField => $sortPayload];
        }
      }

      $options['sort'] = $sorts;
    }

    return $options;
  }

  public function parseFilter($filterType, $filterPayload): void
  {
    switch ($filterType) {
      case 'filterGeoBox':
        self::$filter['filter']['geo_bounding_box'][$filterPayload['field']] = [
          'top_left' => $filterPayload['topLeft'],
          'bottom_right' => $filterPayload['bottomRight'],
        ];
        break;
      case 'filterGeoPoint':
        self::$filter['filter']['geo_distance'] = [
          'distance' => $filterPayload['distance'],
          $filterPayload['field'] => [
            'lat' => $filterPayload['geoPoint'][0],
            'lon' => $filterPayload['geoPoint'][1],
          ],

        ];
        break;
    }
  }

  public function parseFilterParameter($params, $filer): array
  {
    $body = $params['body'];
    $currentQuery = $body['query'];

    $filteredBody = [
      'query' => [
        'bool' => [
          'must' => [
            $currentQuery,
          ],
          'filter' => $filer['filter'],
        ],
      ],
    ];
    $params['body']['query'] = $filteredBody['query'];

    return $params;

  }

  public function parseFunctionScore($params, $function)
  {
    $body = $params['body'];
    $currentQuery = $body['query'];

    $newBody = [
      'query' => [
        'function_score' => [
          'query' => $currentQuery,
          'random_score' => $function['random_score'],
        ],
      ],
    ];
    $params['body'] = $newBody;

    return $params;
  }

  /**
   * @throws QueryException
   */
  public function parseRequiredKeywordMapping($field): ?string
  {
    if (! $this->cachedKeywordFields instanceof Collection) {
      $mapping = $this->processFieldMapping($this->index, '*');
      $fullMap = new Collection($mapping);
      $keywordFields = $fullMap->filter(fn ($value) => $value == 'keyword');
      $this->cachedKeywordFields = $keywordFields;

    }
    $keywordFields = $this->cachedKeywordFields;

    if ($keywordFields->isEmpty()) {
      //No keyword fields
      return null;
    }
    if ($keywordFields->has($field)) {
      //Field is a keyword
      return $field;
    }
    if ($keywordFields->has($field.'.keyword')) {
      // Field has a keyword property
      return $field.'.keyword';
    }

    return null;
  }






  /**
   * @param $value
   * @return mixed
   */
  protected function getStringValue($value)
  {
    // Convert DateTime values to UTCDateTime.
    if ($value instanceof DateTime) {
      $value = $this->convertDateTime($value);
    } else {
        if (is_array($value)) {
          foreach ($value as &$val) {
            if ($val instanceof DateTime) {
              $val = $this->convertDateTime($val);
            }
          }
        }

    }
    return $value;
  }

  /**
   * Compile a delete query
   *
   * @param  Builder  $builder
   * @return string
   */
  protected function convertDateTime($value): string
  {
    if (is_string($value)) {
      return $value;
    }

    return $value->format('c');
  }
}
