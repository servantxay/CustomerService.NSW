<?php
namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;

/**
 * Class Database
 *
 * Singleton class to manage database connection for the application.
 * Supports PostgreSQL (via DATABASE_URL) and falls back to SQLite for local development.
 */
class Database {
    private static ?Connection $conn = null;

    // Get the database connection.
    public static function getConnection(): Connection {
        if (!self::$conn) {
            $dbUrl = getenv('DATABASE_URL');

            try {
                if ($dbUrl) {
                    self::$conn = DriverManager::getConnection(['url' => $dbUrl]);
                    self::$conn->executeQuery('SELECT 1');
                }
            } catch (Exception) {
                $sqlitePath = __DIR__ . '/fines.db';
                self::$conn = DriverManager::getConnection(['url' => 'sqlite:///' . $sqlitePath]);
            }

            if (!self::$conn) {
                $sqlitePath = __DIR__ . '/fines.db';
                self::$conn = DriverManager::getConnection(['url' => 'sqlite:///' . $sqlitePath]);
            }
        }

        return self::$conn;
    }

    // Initialise the database schema.
    public static function init(): void {
        $conn = self::getConnection();
        $schemaManager = $conn->createSchemaManager();
        $schema = new Schema();

        // Offenders table
        self::createOffendersTable($conn, $schema, $schemaManager);

        // Fines table
        self::createOrUpdateFinesTable($conn, $schema, $schemaManager);
    }

    // create offenders table
    private static function createOffendersTable(Connection $conn, Schema $schema, $schemaManager): void {
        if (!$schemaManager->tablesExist(['offenders'])) {
            $table = $schema->createTable('offenders');
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('date_of_birth', 'date', ['notnull' => false]);
            $table->setPrimaryKey(['id']);

            // Apply schema
            $platform = $conn->getDatabasePlatform();
            $sql = $platform->getCreateTableSQL($table);
            foreach ($sql as $stmt) {
                $conn->executeStatement($stmt);
            }
        }
    }

    // create or update fines table
    private static function createOrUpdateFinesTable(Connection $conn, Schema $schema, $schemaManager): void {
        if (!$schemaManager->tablesExist(['fines'])) {
            $table = $schema->createTable('fines');

            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->addColumn('offender_id', 'integer', ['notnull' => true]);
            $table->addColumn('offence_type', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('fine_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => true]);
            $table->addColumn('date_issued', 'date', ['notnull' => true]);
            $table->addColumn('date_paid', 'date', ['notnull' => false]);
            $table->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'unpaid']);
            $table->addColumn('business_flags', 'text', ['notnull' => true, 'default' => '{}']);

            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('offenders', ['offender_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_fines_offender_id');

            $platform = $conn->getDatabasePlatform();
            $sql = $platform->getCreateTableSQL($table);
            foreach ($sql as $stmt) {
                $conn->executeStatement($stmt);
            }
        } else {
            // Backward compatibility
            self::ensureBackwardCompatibility($conn);
        }
    }

    // ensure backward compatibility for new fields introduced
    private static function ensureBackwardCompatibility(Connection $conn): void {
        $schemaManager = $conn->createSchemaManager();
        $currentSchema = $schemaManager->introspectSchema();
        $schema = clone $currentSchema;

        if (!$schema->hasTable('fines')) {
            return;
        }

        $table = $schema->getTable('fines');
        $changed = false;

        // offender_id
        if (!$table->hasColumn('offender_id')) {
            $table->addColumn('offender_id', 'integer', ['notnull' => true]);
            $table->addForeignKeyConstraint('offenders', ['offender_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_fines_offender_id');
            $changed = true;
        }

        // business_flags
        if (!$table->hasColumn('business_flags')) {
            $table->addColumn('business_flags', 'text', ['notnull' => true, 'default' => '{}']);
            $changed = true;
        }

        // date_paid
        if (!$table->hasColumn('date_paid')) {
            $table->addColumn('date_paid', 'date', ['notnull' => false]);
            $changed = true;
        }

        if ($changed) {
            $platform = $conn->getDatabasePlatform();
            $comparator = new \Doctrine\DBAL\Schema\Comparator();
            $schemaDiff = $comparator->compare($currentSchema, $schema);
            $sqlStatements = $platform->getAlterSchemaSQL($schemaDiff);
            foreach ($sqlStatements as $stmt) {
                $conn->executeStatement($stmt);
            }
        }
    }
}
