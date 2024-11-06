<?php

declare(strict_types=1);

namespace PDPhilip\Elasticsearch\Exceptions;

class BulkInsertQueryException extends LaravelElasticsearchException
{
  private int $errorLimit = 10;

  /**
   * BulkInsertQueryException constructor.
   *
   * @param array $queryResult
   */
  public function __construct(array $queryResult)
  {
    parent::__construct($this->formatMessage($queryResult), 400);
  }

  /**
   * Format the error message.
   *
   * Takes the first {$this->errorLimit} bulk issues and concatenates them to a single string message
   *
   * @param  array  $result
   * @return string
   */
  private function formatMessage(array $result): string
  {
    $message = collect();

    // Clean that ish up.
    $items = collect($result['items'] ?? [])
    ->filter(function(array $item) {
      return $item['index'] && !empty($item['index']['error']);
    })
    ->map(function(array $item) {
      return $item['index'];
    })
    // reduce to max limit
    ->slice(0, $this->errorLimit)
    ->values();

    $totalErrors = collect($result['items'] ?? []);

    $message->push('Bulk Insert Errors (' . 'Showing ' . $items->count() . ' of ' . $totalErrors->count() -1 . '):');


    $items = $items->map(function(array $item) {
      return "{$item['_id']}: {$item['error']['reason']}";
    })->values()->toArray();

    $message->push(...$items);

//    foreach ($items as $item) {
//      $itemError = array_merge([
//                                 '_id'  => $item['_id'],
//                                 'reason' => $item['error']['reason'],
//                               ], $item['error'] ?? []);
//
//      $message[] = implode(': ', $itemError);
//    }

    return $message->implode(PHP_EOL);
  }
}
