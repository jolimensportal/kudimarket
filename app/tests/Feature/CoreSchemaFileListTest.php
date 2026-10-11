<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\BuildsLedgerDatabase;
use Tests\TestCase;

/**
 * The SQL schema is loaded into the test database from three separate hard-coded lists.
 *
 * Adding a file to two of the three is not a partial success that shows up later; it is a failure that
 * reads as a bug in code nobody touched. 016_cdd.sql landed in run_tests.sh and in the migration and
 * not here, and the result was "relation cdd_status does not exist" against a file that is plainly in
 * the repository, reported from a controller nobody had edited. This test turns that hour into a second.
 */
class CoreSchemaFileListTest extends TestCase
{
    /** Every .sql file that actually exists, sorted. */
    private function filesOnDisk(): array
    {
        $files = glob(base_path('../kudi-core/*.sql')) ?: [];

        return array_values(array_map(
            static fn (string $path): string => basename($path),
            $files
        ));
    }

    public function test_the_php_test_loader_lists_every_sql_file_on_disk(): void
    {
        $this->assertSame(
            $this->filesOnDisk(),
            BuildsLedgerDatabase::coreFiles(),
            'tests/Support/BuildsLedgerDatabase.php is missing a SQL file that exists in kudi-core/'
        );
    }

    public function test_the_sql_suite_loads_the_same_files(): void
    {
        $runner = file_get_contents(base_path('../kudi-core/run_tests.sh'));

        $this->assertIsString($runner);

        foreach ($this->filesOnDisk() as $file) {
            $this->assertStringContainsString(
                $file,
                (string) $runner,
                "kudi-core/run_tests.sh does not load {$file}"
            );
        }
    }

    public function test_the_migration_loads_the_same_files(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/2026_01_01_000100_create_kudi_core.php')
        );

        $this->assertIsString($migration);

        foreach ($this->filesOnDisk() as $file) {
            $this->assertStringContainsString(
                $file,
                (string) $migration,
                "the create_kudi_core migration does not load {$file}"
            );
        }
    }
}
