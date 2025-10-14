<?php
namespace Tests\App;

/**
 * Unit Test Usage: (from project root folder)
 * $ ./vendor/bin/phpunit --testdox tests/DatabaseTest.php
 * 
 * Unit Tests for Database.php
 * 
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase {
    private static Connection $conn;

    public static function setUpBeforeClass(): void {
        // Use an in-memory SQLite database for testing
        self::$conn = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);

        // Create the table once in-memory
        self::$conn->executeStatement("
            CREATE TABLE fines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                offender_name VARCHAR(255) NOT NULL,
                offence_type VARCHAR(255) NOT NULL,
                fine_amount DECIMAL(10,2) NOT NULL,
                date_issued DATE NOT NULL,
                date_paid DATE NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'unpaid'
            )
        ");
    }

    protected function setUp(): void {
        // Clear table before each test
        self::$conn->executeStatement("DELETE FROM fines");
    }

    public function testInsertAndRetrieveFine(): void {
        $data = [
            'offender_name' => 'John Tester',
            'offence_type' => 'Speeding',
            'fine_amount' => 150.00,
            'date_issued' => '2025-10-14',
            'status' => 'unpaid'
        ];

        self::$conn->insert('fines', $data);
        $result = self::$conn->fetchAssociative("SELECT * FROM fines WHERE offender_name = 'John Tester'");

        $this->assertNotEmpty($result);
        $this->assertSame('Speeding', $result['offence_type']);
        $this->assertSame('unpaid', $result['status']);
    }

    public function testUpdateFineStatus(): void  {
        self::$conn->insert('fines', [
            'offender_name' => 'Jane Tester',
            'offence_type' => 'Parking',
            'fine_amount' => 75.00,
            'date_issued' => '2025-10-14',
            'status' => 'unpaid'
        ]);

        self::$conn->update('fines', ['status' => 'paid'], ['offender_name' => 'Jane Tester']);
        $updated = self::$conn->fetchAssociative("SELECT status FROM fines WHERE offender_name = 'Jane Tester'");

        $this->assertSame('paid', $updated['status']);
    }

    public function testDeleteFine(): void {
        self::$conn->insert('fines', [
            'offender_name' => 'Bob Smith',
            'offence_type' => 'Littering',
            'fine_amount' => 50.00,
            'date_issued' => '2025-10-14',
            'status' => 'unpaid'
        ]);

        self::$conn->delete('fines', ['offender_name' => 'Bob Smith']);
        $deleted = self::$conn->fetchAssociative("SELECT * FROM fines WHERE offender_name = 'Bob Smith'");

        $this->assertFalse($deleted);
    }

    public function testDatePaidCanBeNull(): void {
        // Insert record with NULL date_paid
        self::$conn->insert('fines', [
            'offender_name' => 'Null Date',
            'offence_type' => 'Testing',
            'fine_amount' => 99.99,
            'date_issued' => '2025-10-14',
            'date_paid' => null,
            'status' => 'unpaid'
        ]);

        $result = self::$conn->fetchAssociative("SELECT * FROM fines WHERE offender_name = 'Null Date'");

        $this->assertArrayHasKey('date_paid', $result);
        $this->assertNull($result['date_paid']);
    }

    public function testDatePaidCanBeSet(): void {
        $paidDate = '2025-10-15';

        self::$conn->insert('fines', [
            'offender_name' => 'Paid Date',
            'offence_type' => 'Testing',
            'fine_amount' => 99.99,
            'date_issued' => '2025-10-14',
            'date_paid' => $paidDate,
            'status' => 'paid'
        ]);

        $result = self::$conn->fetchAssociative("SELECT * FROM fines WHERE offender_name = 'Paid Date'");
        $this->assertSame($paidDate, $result['date_paid']);
    }
}