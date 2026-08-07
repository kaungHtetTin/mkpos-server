<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;

class ImportSqlite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mkpos:import-sqlite
                            {business : ID of the business that will own the imported data}
                            {path? : Path to mkpos.sqlite3 (defaults to ../data/mkpos.sqlite3)}
                            {--truncate : Replace existing MySQL POS data before importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import an existing MKPOS SQLite database into MySQL';

    private array $tables = [
        'products', 'product_prices', 'price_type_rules', 'customers', 'suppliers',
        'sales', 'sale_items', 'purchases', 'purchase_items', 'customer_payments',
        'expenses', 'settings', 'stock_movements',
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $businessId = (int) $this->argument('business');
        if (! Business::whereKey($businessId)->where('status', 'active')->exists()) {
            $this->error("Active business {$businessId} was not found.");

            return Command::FAILURE;
        }

        $idTables = ['products', 'customers', 'suppliers', 'sales', 'sale_items', 'purchases', 'purchase_items', 'customer_payments', 'expenses', 'stock_movements'];
        $otherBusinessData = collect($idTables)->first(fn ($table) => Schema::hasTable($table)
            && DB::table($table)->where('business_id', '<>', $businessId)->exists());
        if ($otherBusinessData) {
            $this->error("Import refused because {$otherBusinessData} already contains another business's data and legacy IDs may overlap.");

            return Command::FAILURE;
        }

        $path = $this->argument('path') ?: base_path('../data/mkpos.sqlite3');
        $path = realpath($path) ?: $path;
        if (! is_file($path)) {
            $this->error("SQLite database not found: {$path}");

            return Command::FAILURE;
        }
        if (config('database.default') !== 'mysql') {
            $this->error('The target connection must be MySQL.');

            return Command::FAILURE;
        }

        $nonEmpty = collect($this->tables)->first(fn ($table) => Schema::hasTable($table) && DB::table($table)->where('business_id', $businessId)->exists());
        if ($nonEmpty && ! $this->option('truncate')) {
            $this->error("MySQL table {$nonEmpty} already contains data. Re-run with --truncate only after making a backup.");

            return Command::FAILURE;
        }

        app(TenantContext::class)->set($businessId);
        try {
            $source = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $sourceTables = collect($source->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN));
            foreach (['products', 'customers', 'sales', 'sale_items', 'settings'] as $required) {
                if (! $sourceTables->contains($required)) {
                    throw new RuntimeException("Source database is missing required table {$required}.");
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::transaction(function () use ($source, $sourceTables) {
                if ($this->option('truncate')) {
                    foreach (array_reverse($this->tables) as $table) {
                        DB::table($table)->delete();
                    }
                }
                foreach ($this->tables as $table) {
                    if (! $sourceTables->contains($table)) {
                        $this->warn("Skipping source table not present in this SQLite version: {$table}");

                        continue;
                    }
                    $targetColumns = Schema::getColumnListing($table);
                    $sourceColumns = array_column($source->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
                    $columns = array_values(array_intersect($sourceColumns, $targetColumns));
                    if (! $columns) {
                        continue;
                    }
                    $quoted = implode(', ', array_map(fn ($column) => "\"{$column}\"", $columns));
                    $statement = $source->query("SELECT {$quoted} FROM \"{$table}\"");
                    $count = 0;
                    $chunk = [];
                    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                        $chunk[] = $row;
                        if (count($chunk) === 500) {
                            DB::table($table)->insert($chunk);
                            $count += count($chunk);
                            $chunk = [];
                        }
                    }
                    if ($chunk) {
                        DB::table($table)->insert($chunk);
                        $count += count($chunk);
                    }
                    $this->line("Imported {$table}: {$count}");
                }
            });
        } catch (\Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());

            return Command::FAILURE;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            app(TenantContext::class)->clear();
        }

        $this->info('SQLite data imported into MySQL successfully.');

        return Command::SUCCESS;
    }
}
