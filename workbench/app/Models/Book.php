<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PDPhilip\Elasticsearch\Eloquent\Model;
use PDPhilip\Elasticsearch\Schema\Blueprint;
use PDPhilip\Elasticsearch\Schema\Schema;

/**
 * @property string $title
 * @property string $author
 * @property array $chapters
 */
class Book extends Model
{
    protected $connection = 'elasticsearch';

    protected $index = 'books';

    protected static $unguarded = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function sqlAuthor(): BelongsTo
    {
        return $this->belongsTo(SqlUser::class, 'author_id');
    }

    /**
     * Check if we need to run the schema.
     */
    public static function executeSchema()
    {
        $schema = Schema::connection('elasticsearch');

        $schema->dropIfExists('books');
        $schema->create('books', function (Blueprint $table) {
            $table->date('created_at');
            $table->date('updated_at');
        });
    }
}
