<?php

namespace Naoray\LaravelFactoryPrefill\Commands;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Naoray\LaravelFactoryPrefill\TypeGuesser;

class PrefillFactory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'factory:prefill 
                                {model : The name of the model for which a blueprint will be created}
                                {--O|own-namespace : When using this flag the model have to includ the full namespace}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prefills factory for the given model with a faker method suggestions.';

    /**
     * @var \App\TypeGuesser $guesser
     */
    protected $typeGuesser;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(TypeGuesser $guesser)
    {
        parent::__construct();

        $this->typeGuesser = $guesser;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // get model
        $model = $this->argument('model');

        // check if model exists
        if (!class_exists($modelClass = $this->qualifyClass($model))) {
            $this->error($modelClass . ' could not be found!');

            $createModel = $this->confirm("Do you wish me to create {$modelClass} for you?");

            if ($createModel) {
                $this->call('make:model', ['name' => $modelClass]);
                $this->info("Please repeat the factory:prefill $model command.");
            }

            return false;
        }

        $factoryName = collect(explode('\\', $model))->last();

        // check if factory exists
        if (File::exists($factoryPath = database_path("factories/{$factoryName}Factory.php")) &&
            !$this->confirm("A factory file for $model already exists, do you wish to overwrite the existing file?")) {
            $this->info('Canceled blueprint creation!');
            return false;
        }

        // get table name from model
        $tableName = (new $modelClass)->getTable();
        $tableIndexes = DB::getDoctrineSchemaManager()->listTableIndexes($tableName);

        // get column names
        $columnListing = Schema::getColumnListing($tableName);

        // foreach column name get the type
        $columnData = collect($columnListing)->map(function ($column) use ($tableName, $tableIndexes, $modelClass) {
            $data = (object) DB::connection()->getDoctrineColumn($tableName, $column)->toArray();

            if (!$data->notnull || $data->autoincrement) {
                return null;
            }

            $isForeignKey = $this->isForeignKey($column, $tableIndexes);
            $isUnique = $this->isUnique($column, $tableIndexes);

            $value = $isForeignKey
                ? $this->buildRelationFunction($modelClass, $data->name)
                : ($isUnique ? '$faker->unique()->' : '$faker->') . $this->mapToFaker($data);

            return "'$data->name' => $value";
        })->filter(function ($data) {
            return !!$data;
        })->values()->toArray();

        $this->writeFactoryFile($factoryPath, $columnData, $modelClass);
        $this->info('Factory blueprint created!');
    }

    /**
     * Map name to faker method.
     *
     * @param string $name
     * @return string
     */
    protected function mapToFaker($data)
    {
        return $this->typeGuesser->guess($data->name, $data->length, $data->type);
    }

    /**
     * Check if column is a foreign key.
     *
     * @param string $name
     * @param array $tableIndexes
     * @return boolean
     */
    protected function isForeignKey($name, $tableIndexes)
    {
        return !!Arr::where(array_keys($tableIndexes), function ($index) use ($name) {
            return Str::contains($index, 'foreign') && Str::contains($index, $name);
        });
    }

    /**
     * Check if column is a unique one.
     *
     * @param string $name
     * @param array $tableIndexes
     * @return boolean
     */
    protected function isUnique($name, $tableIndexes)
    {
        return !!Arr::where(array_keys($tableIndexes), function ($index) use ($name) {
            return Str::contains($index, 'unique') && Str::contains($index, $name);
        });
    }

    /**
     * Build relation function.
     *
     * @param string $model
     * @param string $column
     * @return string
     */
    public function buildRelationFunction($model, $column)
    {
        $relationName = str_replace('_id', '', $column);
        $foreignCallback = 'factory(App\REPLACE_THIS::class)->lazy()';

        try {
            $relatedModel = get_class((new $model)->$relationName()->getRelated());

            return str_replace('App\REPLACE_THIS', $relatedModel, $foreignCallback);
        } catch (\Exception $e) {
            return $foreignCallback;
        }
    }

    /**
     * Parse the class name and format according to the root namespace.
     *
     * @param  string  $name
     * @return string
     */
    protected function qualifyClass($name)
    {
        $name = ltrim($name, '\\/');

        $rootNamespace = app()->getNamespace();

        if ($this->option('own-namespace') || starts_with($name, $rootNamespace)) {
            return $name;
        }

        $name = str_replace('/', '\\', $name);

        return $this->qualifyClass(
            trim($rootNamespace, '\\') . '\\' . $name
        );
    }

    /**
     * Writes data to factory file.
     *
     * @param string $path
     * @param array $data
     * @return void
     */
    protected function writeFactoryFile($path, $data, $modelClass)
    {
        File::put($path, "<?php

use Faker\Generator as Faker;

\$factory->define($modelClass::class, function (Faker \$faker) {
    return [
        " . implode(",\r\t\t", $data) . '
    ];
});');
    }
}
