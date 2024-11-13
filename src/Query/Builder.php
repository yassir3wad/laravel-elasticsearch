<?php

declare(strict_types=1);

namespace PDPhilip\Elasticsearch\Query;

use AllowDynamicProperties;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDPhilip\Elasticsearch\Connection;
use PDPhilip\Elasticsearch\Data\Result;
use PDPhilip\Elasticsearch\Traits\HasOptions;
use PDPhilip\Elasticsearch\Traits\Query\ManagesParameters;

/**
 * @property Connection $connection
 * @property Processor $processor
 * @property Grammar $grammar
 */
#[AllowDynamicProperties]
class Builder extends BaseBuilder
{
    use HasOptions;
    use ManagesParameters;

    /** @var string[] */
    public const REFRESH = [
        'FALSE' => false,
        'TRUE' => true,
        'WAIT_FOR' => 'wait_for',
    ];

    /** @var string[] */
    public const CONFLICT = [
        'ABORT' => 'abort',
        'PROCEED' => 'proceed',
    ];

    public $type;

    public $aggregations;

    public $filters;

    public $postFilters;

    public $includeInnerHits;

    protected $parentId;

    protected $results;

    protected array $mapping = [];

    /**
     * {@inheritdoc}
     */
    public $limit = 10000;

    /** @var int */
    protected $resultsOffset;

    protected $rawResponse;

    protected $routing;

    public $distinct;

    public $scripts = [];

    /**
     * All of the supported clause operators.
     *
     * @var array
     */
    public $operators = ['=', '<', '>', '<=', '>=', '<>', '!=', 'exists', 'like', 'not like'];
    /**
     * Set the document type the search is targeting.
     *
     * @param  string  $type
     */
    public function type($type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Set the parent ID to be used when routing queries to Elasticsearch
     */
    public function parentId(string $id): self
    {
        $this->parentId = $id;

        return $this;
    }

    /**
     * Get the parent ID to be used when routing queries to Elasticsearch
     */
    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    /**
     * @return QueryBuilder
     */
    public function routing(string $routing): self
    {
        $this->routing = $routing;

        return $this;
    }

    public function getRouting(): ?string
    {
        return $this->routing;
    }

    /**
     * @return mixed|null
     */
    public function getOption(string $option)
    {
        return $this->options()->get($option);
    }

    public function truncate()
    {
        $this->applyBeforeQueryCallbacks();
        $this->connection->delete($this->grammar->compileTruncate($this));
    }

    /**
     * Get results without re-fetching for subsequent calls.
     *
     * @return array
     */
    public function getMapping()
    {
        if (empty($this->mapping)) {
            $this->mapping = $this->connection->indices()->getMapping($this->grammar->compileIndexMappings($this))->asArray();
        }

        return $this->mapping;
    }

    /**
     * Force the query to only return distinct results.
     *
     * @return $this
     */
    public function distinct()
    {
        $columns = func_get_args();

        if (count($columns) > 0) {
            $this->distinct = is_array($columns[0]) ? $columns[0] : $columns;
        } else {
            $this->distinct = [];
        }

        return $this;
    }

    /**
     * Add a where between statement to the query.
     *
     * @param  string  $column
     * @param  array  $values
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false): self
    {
        $type = 'Between';

        $this->wheres[] = compact('column', 'values', 'type', 'boolean', 'not');

        return $this;
    }

    /**
     * Add a 'distance from point' statement to the query.
     *
     * @param  string  $column
     * @param  array  $coords
     * @param  string  $boolean
     */
    public function whereGeoDistance($column, array $location, string $distance, $boolean = 'and', bool $not = false): self
    {
        $type = 'GeoDistance';

        $this->wheres[] = compact('column', 'location', 'distance', 'type', 'boolean', 'not');

        return $this;
    }

  /**
   * Add a 'regexp' statement to the query.
   *
   * @param string $column
   * @param string $value
   * @param string $boolean
   * @param bool   $not
   * @param array  $parameters
   *
   * @return Builder
   */
    public function whereRegex($column, string $value, $boolean = 'and', bool $not = false, array $parameters = []): self
    {
        $type = 'Regex';

        $this->wheres[] = compact('column', 'value', 'type', 'boolean', 'not', 'parameters');

        return $this;
    }

    /**
     * Add a 'distance from point' statement to the query.
     *
     * @param  string  $column
     */
    public function whereGeoBoundsIn($column, array $bounds): self
    {
        $type = 'GeoBoundsIn';

        $this->wheres[] = [
            'column' => $column,
            'bounds' => $bounds,
            'type' => 'GeoBoundsIn',
            'boolean' => 'and',
            'not' => false,
        ];

        return $this;
    }

    /**
     * Add a "where date" statement to the query.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereDate($column, $operator, $value = null, $boolean = 'and', $not = false): self
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() == 2
        );

        $type = 'Date';

        $this->wheres[] = compact('column', 'operator', 'value', 'type', 'boolean', 'not');

        return $this;
    }

    /**
     * Add a 'nested document' statement to the query.
     *
     * @param  string  $column
     * @param  callable|\Illuminate\Database\Query\Builder|static  $query
     * @param  string  $boolean
     */
    public function whereNestedDoc($column, $query, $boolean = 'and'): self
    {
        $type = 'NestedDoc';

        if (! is_string($query) && is_callable($query)) {
            call_user_func($query, $query = $this->newQuery());
        }

        $this->wheres[] = compact('column', 'query', 'type', 'boolean');

        return $this;
    }

    /**
     * Add a 'must not' statement to the query.
     *
     * @param  \Illuminate\Database\Query\Builder|static  $query
     * @param  string  $boolean
     */
    public function whereNot($query, $operator = null, $value = null, $boolean = 'and'): self
    {
        $type = 'Not';

        call_user_func($query, $query = $this->newQuery());

        $this->wheres[] = compact('query', 'type', 'boolean');

        return $this;
    }

    /**
     * Add a prefix query
     *
     * @param  string  $column
     * @param  string  $boolean
     * @param  bool  $not
     */
    public function whereStartsWith($column, string $value, $boolean = 'and', $not = false): self
    {
        $type = 'Prefix';

        $this->wheres[] = compact('column', 'value', 'type', 'boolean', 'not');

        return $this;
    }

    /**
     * Add a script query
     *
     * @param  string  $boolean
     */
    public function whereScript(string $script, array $options = [], $boolean = 'and'): self
    {
        $type = 'Script';

        $this->wheres[] = compact('script', 'options', 'type', 'boolean');

        return $this;
    }

    /**
     * Add a "where weekday" statement to the query.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  \DateTimeInterface|string  $value
     * @param  string  $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereWeekday($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        if ($value instanceof DateTimeInterface) {
            $value = $value->format('N');
        }

        return $this->addDateBasedWhere('Weekday', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where weekday" statement to the query.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  \DateTimeInterface|string  $value
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function orWhereWeekday($column, $operator, $value = null)
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->addDateBasedWhere('Weekday', $column, $operator, $value, 'or');
    }

    /**
     * Add a date based (year, month, day, time) statement to the query.
     *
     * @param  string  $type
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    protected function addDateBasedWhere($type, $column, $operator, $value, $boolean = 'and')
    {
        switch ($type) {
            case 'Year':
                $dateType = 'year';
                break;

            case 'Month':
                $dateType = 'monthOfYear.value';
                break;

            case 'Day':
                $dateType = 'dayOfMonth.value';
                break;

            case 'Weekday':
                $dateType = 'dayOfWeekEnum.value';
                break;
        }

        $type = 'Script';

        $operator = $operator == '=' ? '==' : $operator;
        $operator = $operator == '<>' ? '!=' : $operator;

        $script = "doc.{$column}.size() > 0 && doc.{$column}.value != null && doc.{$column}.value.{$dateType} {$operator} params.value";

        $options['params'] = ['value' => (int) $value];

        $this->wheres[] = compact('script', 'options', 'type', 'boolean');

        return $this;
    }

    /**
     * Add another query builder as a nested where to the query builder.
     *
     * @param  \Illuminate\Database\Query\Builder|static  $query
     * @param  string  $boolean
     */
    public function addNestedWhereQuery($query, $boolean = 'and'): self
    {
        $type = 'Nested';

        $compiled = compact('type', 'query', 'boolean');

        if (count($query->wheres)) {
            $this->wheres[] = $compiled;
        }

        if (isset($query->filters) && count($query->filters)) {
            $this->filters[] = $compiled;
        }

        return $this;
    }

    /**
     * Add any where clause with given options.
     */
    public function whereWithOptions(...$args): self
    {
        $options = array_pop($args);
        $type = array_shift($args);
        $method = $type == 'Basic' ? 'where' : 'where'.$type;

        $this->$method(...$args);

        $this->wheres[count($this->wheres) - 1]['options'] = $options;

        return $this;
    }

    /**
     * Add a filter query by calling the required 'where' method
     * and capturing the added where as a filter
     */
    public function dynamicFilter(string $method, array $args): self
    {
        $method = ucfirst(substr($method, 6));

        $numWheres = count($this->wheres);

        $this->$method(...$args);

        $filterType = array_pop($args) === 'postFilter' ? 'postFilters' : 'filters';

        if (count($this->wheres) > $numWheres) {
            $this->$filterType[] = array_pop($this->wheres);
        }

        return $this;
    }

    /**
     * Add a text search clause to the query.
     *
     * @param  string  $query
     * @param  array  $options
     * @param  string  $boolean
     */
    public function search($query, $options = [], $boolean = 'and'): self
    {
        $this->wheres[] = [
            'type' => 'Search',
            'value' => $query,
            'boolean' => $boolean,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * @param  string  $parentType  Name of the parent relation from the join mapping
     * @param  mixed  $id
     * @return QueryBuilder
     */
    public function whereParentId(string $parentType, $id, string $boolean = 'and'): self
    {
        $this->wheres[] = [
            'type' => 'ParentId',
            'parentType' => $parentType,
            'id' => $id,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a where parent statement to the query.
     *
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereParent(
        string $documentType,
        Closure $callback,
        array $options = [],
        string $boolean = 'and'
    ): self {
        return $this->whereRelationship('parent', $documentType, $callback, $options, $boolean);
    }

    /**
     * Add a where child statement to the query.
     *
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereChild(
        string $documentType,
        Closure $callback,
        array $options = [],
        string $boolean = 'and'
    ): self {
        return $this->whereRelationship('child', $documentType, $callback, $options, $boolean);
    }

    /**
     * Add a where relationship statement to the query.
     *
     *
     * @return \Illuminate\Database\Query\Builder|static
     */
    protected function whereRelationship(
        string $relationshipType,
        string $documentType,
        Closure $callback,
        array $options = [],
        string $boolean = 'and'
    ): self {
        call_user_func($callback, $query = $this->newQuery());

        $this->wheres[] = [
            'type' => ucfirst($relationshipType),
            'documentType' => $documentType,
            'value' => $query,
            'options' => $options,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * @param  string  $column
     * @param  int  $direction
     * @param  array  $options
     */
    public function orderBy($column, $direction = 1, $options = null): self
    {
        if (is_string($direction)) {
            $direction = strtolower($direction) == 'asc' ? 1 : -1;
        }

        $type = isset($options['type']) ? $options['type'] : 'basic';

        $this->orders[] = compact('column', 'direction', 'type', 'options');

        return $this;
    }

    /**
     * Whether to include inner hits in the response
     */
    public function withInnerHits(): self
    {
        $this->includeInnerHits = true;

        return $this;
    }

    /**
     * Set whether to refresh during delete by query
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-refresh.html
     *
     * @param  string  $option
     *
     * @throws \Exception
     */
    public function withRefresh($option = self::REFRESH['FALSE']): self
    {
        if (in_array($option, self::REFRESH)) {
            $this->options['refresh'] = $option;

            return $this;
        }

        throw new \Exception(
            "$option is an invalid refresh option, valid options are: ".implode(', ', self::REFRESH)
        );
    }

    /**
     * Set how to handle conflicts during a delete request
     *
     * @link https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-delete-by-query.html#docs-delete-by-query-api-query-params
     *
     * @throws \Exception
     */
    public function onConflicts(string $option = self::CONFLICT['ABORT']): self
    {
        if (in_array($option, self::CONFLICT)) {
            $this->options['delete_conflicts'] = $option;

            return $this;
        }

        throw new \Exception(
            "$option is an invalid conflict option, valid options are: ".implode(', ', self::CONFLICT)
        );
    }

    /**
     * Adds a function score of any type
     *
     * @param  string  $field
     * @param  array  $options  see elastic search docs for options
     * @param  string  $boolean
     */
    public function functionScore($functionType, $options = [], $boolean = 'and'): self
    {
        $where = [
            'type' => 'FunctionScore',
            'function_type' => $functionType,
            'boolean' => $boolean,
        ];

        $this->wheres[] = array_merge($where, $options);

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return \Illuminate\Support\Collection
     */
    public function get($columns = ['*'])
    {
        $original = $this->columns;

        if (is_null($original)) {
            $this->columns = $columns;
        }

        $results = $this->getResultsOnce();

        $this->columns = $original;

        return collect($results);
    }

    /**
     * Get results without re-fetching for subsequent calls.
     *
     * @return array
     */
    protected function getResultsOnce()
    {
        if (! $this->hasProcessedSelect()) {
            $this->results = $this->processor->processSelect($this, $this->runSelect());
        }

        $this->resultsOffset = $this->offset;

        return $this->results;
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return iterable
     */
    protected function runSelect()
    {
        return $this->connection->select($this->toCompiledQuery());
    }

    /**
     * Get the count of the total records for the paginator.
     *
     * @param  array  $columns
     * @return int
     */
    public function getCountForPagination($columns = ['*'])
    {
        if ($this->results === null) {
            $this->runPaginationCountQuery();
        }

        $total = $this->processor->getRawResponse()['hits']['total'];

        return is_array($total) ? $total['value'] : $total;
    }

    /**
     * Run a pagination count query.
     *
     * @param  array  $columns
     * @return array
     */
    protected function runPaginationCountQuery($columns = ['_id'])
    {
        return $this->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->limit(1)
            ->get($columns)->all();
    }

    /**
     * Get the time it took Elasticsearch to perform the query
     *
     * @return int time in milliseconds
     */
    public function getSearchDuration()
    {
        if (! $this->hasProcessedSelect()) {
            $this->getResultsOnce();
        }

        return $this->processor->getRawResponse()['took'];
    }

    /**
     * Get the Elasticsearch representation of the query.
     */
    public function toCompiledQuery(): array
    {
        return $this->toSql();
    }

    /**
     * Get a generator for the given query.
     *
     * @return \Generator
     */
    public function cursor()
    {
        if (is_null($this->columns)) {
            $this->columns = ['*'];
        }

        foreach ($this->connection->cursor($this->toCompiledQuery()) as $document) {
            yield $this->processor->documentFromResult($this, $document);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function insert(array $values): bool
    {
        // Since every insert gets treated like a batch insert, we will have to detect
        // if the user is inserting a single document or an array of documents.
        $batch = true;

        foreach ($values as $value) {
            // As soon as we find a value that is not an array we assume the user is
            // inserting a single document.
            if (! is_array($value)) {
                $batch = false;
                break;
            }
        }

        if (! $batch) {
            $values = [$values];
        }

        return ! $this->connection->insert($this->grammar->compileInsert($this, $values))['errors'];
    }

    /**
     * Append one or more values to an array.
     *
     * @param  string|array  $column
     * @param  mixed  $value
     * @param  bool  $unique
     * @return int
     */
    public function push($column, $value = null, $unique = false)
    {
        // Check if we are pushing multiple values.
        $batch = is_array($value) && array_is_list($value);

        $value = $batch ? $value : [$value];

        // Prepare the script for unique or non-unique addition.
        if ($unique) {
            $script = "
            if (ctx._source.{$column} == null) {
                ctx._source.{$column} = [];
            }
            for (item in params.push_values) {
                if (!ctx._source.{$column}.contains(item)) {
                    ctx._source.{$column}.add(item);
                }
            }
        ";
        } else {
            $script = "
            if (ctx._source.{$column} == null) {
                ctx._source.{$column} = [];
            }
            ctx._source.{$column}.addAll(params.push_values);
        ";
        }

        $options['params'] = ['push_values' => $value];
        $this->scripts[] = compact('script', 'options');

        return $this->update([]);
    }

    /**
     * Remove one or more values from an array.
     *
     * @param  string|array  $column
     * @param  mixed  $value
     * @return int
     */
    public function pull($column, $value = null)
    {
        $value = is_array($value) ? $value : [$value];

        // Prepare the script for pulling/removing values.
        $script = "
        if (ctx._source.{$column} != null) {
            ctx._source.{$column}.removeIf(item -> {
                for (removeItem in params.pull_values) {
                    if (item == removeItem) {
                        return true;
                  }
                }
                return false;
            });
        }
    ";

        $options['params'] = ['pull_values' => $value];
        $this->scripts[] = compact('script', 'options');

        return $this->update([]);
    }

    /**
     * {@inheritdoc}
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        return $this->buildCrementEach($columns, 'increment', $extra);
    }

    /**
     * {@inheritdoc}
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        return $this->buildCrementEach($columns, 'decrement', $extra);
    }

    /**
     * Build and add increment or decrement scripts for the given columns.
     *
     * @param  array  $columns  Associative array of columns and their corresponding increment/decrement amounts.
     * @param  string  $type  Type of operation, either 'increment' or 'decrement'.
     * @param  array  $extra  Additional options for the update.
     * @return mixed The result of the update operation.
     *
     * @throws InvalidArgumentException If a non-numeric value is passed as an increment amount
     *                                  or a non-associative array is passed to the method.
     */
    private function buildCrementEach(array $columns, string $type, array $extra = [])
    {
        foreach ($columns as $column => $amount) {
            if (! is_numeric($amount)) {
                throw new InvalidArgumentException("Non-numeric value passed as increment amount for column: '$column'.");
            } elseif (! is_string($column)) {
                throw new InvalidArgumentException('Non-associative array passed to incrementEach method.');
            }

            $operator = $type == 'increment' ? '+' : '-';

            $script = implode('', [
                "if (ctx._source.{$column} == null) { ctx._source.{$column} = 0; }",
                "ctx._source.{$column} $operator= params.{$type}_{$column}_value;",
            ]);

            $options['params'] = ["{$type}_{$column}_value" => (int) $amount];

            $this->scripts[] = compact('script', 'options');
        }

        if (empty($this->wheres)) {
            $this->wheres[] = [
                'type' => 'MatchAll',
                'boolean' => 'and',
            ];
        }

        return $this->update($extra);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($id = null): bool
    {
        // If an ID is passed to the method, we will set the where clause to check the
        // ID to let developers to simply and quickly remove a single row from this
        // database without manually specifying the "where" clauses on the query.
        if (! is_null($id)) {
            $this->where('id', '=', $id);
        }

        $result = $this->connection->delete($this->grammar->compileDelete($this));

        return ! empty($result['deleted']);
    }

    public function aggregate($function, $columns = ['*'])
    {
        return match ($function) {
            //      'count' => $this->aggregateCount($columns),
            'count', 'avg', 'max', 'min', 'sum' => $this->aggregateMetric($function, $columns),
        };
    }

    public function aggregateCount($columns): int
    {
        $params = $this->toSql();
        $result = $this->connection->count(['index' => $params['index']]);

        return $result->asArray()['count'];
    }

    public function aggregateMetric($function, $columns = ['*'])
    {

        $column = reset($columns);
        $this->aggregations[] = [
            'key' => $column,
            'args' => $column,
            'type' => $function,
        ];

        $result = $this->connection->search($this->grammar->compileAggregations($this), []);
        $result = $result->asArray();

        return $result['aggregations'][$column]['value'];
    }

    public function __call($method, $parameters)
    {
        if (Str::startsWith($method, 'filterWhere')) {
            return $this->dynamicFilter($method, $parameters);
        }

        return parent::__call($method, $parameters);
    }

    protected function hasProcessedSelect(): bool
    {
        if ($this->results === null) {
            return false;
        }

        return $this->offset === $this->resultsOffset;
    }
}
