<?php

namespace Statamic\Search\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Events\SearchIndexUpdated;
use Statamic\Facades\Search;
use Statamic\Support\Str;

use function Laravel\Prompts\select;

class Update extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:search:update
        { index? : The handle of the index to update. }
        { --all : Update all indexes. }
        { --chunk= : Process in chunks of the specified size. }';

    protected $description = 'Update a search index';

    private $indexes;

    public function handle()
    {
        foreach ($this->getIndexes() as $index) {
            if ($chunkSize = $this->option('chunk')) {
                $this->updateInChunks($index, (int) $chunkSize);
            } else {
                $index->update();
            }

            SearchIndexUpdated::dispatch($index);

            $this->components->info("Index <comment>{$index->name()}</comment> updated.");
        }
    }

    private function updateInChunks($index, int $chunkSize)
    {
        $reflection = new \ReflectionClass($index);
        $deleteMethod = $reflection->getMethod('deleteIndex');
        $deleteMethod->setAccessible(true);
        $deleteMethod->invoke($index);

        $processedCount = 0;

        $index->searchables()->lazy()->each(function ($searchables) use ($index, $chunkSize, &$processedCount) {
            $searchables->chunk($chunkSize)->each(function ($chunk) use ($index, &$processedCount) {
                $index->insertMultiple($chunk);
                $processedCount += $chunk->count();
                $this->line("Processed {$processedCount} items");
            });
        });
    }

    private function getIndexes()
    {
        if ($requestedIndex = $this->getRequestedIndex()) {
            return $requestedIndex;
        }

        if ($this->option('all')) {
            return $this->indexes();
        }

        if ($this->indexes()->count() === 1) {
            return $this->indexes();
        }

        $selection = select(
            label: 'Which search index would you like to update?',
            options: collect(['All'])->merge($this->indexes()->keys())->all(),
            default: 'All'
        );

        return ($selection == 'All') ? $this->indexes() : [$this->indexes()->get($selection)];
    }

    private function indexes()
    {
        return $this->indexes = $this->indexes ?? Search::indexes();
    }

    private function getRequestedIndex()
    {
        if (! $arg = $this->argument('index')) {
            return;
        }

        if ($this->indexes()->has($arg)) {
            return [$this->indexes()->get($arg)];
        }

        // They might have entered a name as it appears in the config, but if it
        // should be localized we'll get all of the localized versions.
        if (collect(config('statamic.search.indexes'))->put('cp', [])->has($arg)) {
            return $this->indexes()->filter(fn ($index) => Str::startsWith($index->name(), $arg))->all();
        }

        throw new \InvalidArgumentException("Index [$arg] does not exist.");
    }
}
