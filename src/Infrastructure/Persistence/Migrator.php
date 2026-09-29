<?php

declare(strict_types=1);

namespace Terrarium\Infrastructure\Persistence;

/**
 * Applies plain .sql migration files in order: database/migrations/{mysql|sqlite}/NNN_name.sql
 * Applied versions are tracked in the schema_migrations table.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsPath
    ) {}

    /** @return list<string> names of newly applied migrations */
    public function migrate(): array
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(191) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)'
        );

        $applied = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob($this->migrationsPath . '/' . $this->db->driver() . '/*.sql') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            foreach ($this->splitStatements((string) file_get_contents($file)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => Database::now()]);
            $ran[] = $version;
        }
        return $ran;
    }

    /** @return list<string> */
    public function pending(): array
    {
        try {
            $applied = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        } catch (\PDOException) {
            $applied = [];
        }
        $files = glob($this->migrationsPath . '/' . $this->db->driver() . '/*.sql') ?: [];
        $versions = array_map(fn ($f) => basename($f, '.sql'), $files);
        sort($versions);
        return array_values(array_diff($versions, $applied));
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        // strip "-- comments" lines, then split on semicolons at line ends
        $lines = array_filter(
            preg_split('/\R/u', $sql) ?: [],
            fn ($l) => !str_starts_with(ltrim($l), '--')
        );
        $parts = preg_split('/;\s*(\R|$)/u', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
