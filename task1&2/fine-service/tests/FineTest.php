<?php
namespace Tests\App;

/**
 * Unit Test Usage: (from project root folder)
 * $ ./vendor/bin/phpunit --testdox tests/FineTest.php
 * 
 * Unit Tests for Fine.php
 * 
 */

use PHPUnit\Framework\TestCase;
use App\Fine;
use App\FineStatus;

class FineTest extends TestCase {

    public function testFineCreationWithAllFields(): void {
        $data = [
            'id' => 1,
            'offender_name' => 'John Tester',
            'offence_type' => 'Speeding',
            'fine_amount' => 200.50,
            'date_issued' => '2025-10-13',
            'date_paid' => '2025-10-20',
            'status' => FineStatus::PAID->value
        ];

        $fine = new Fine($data);

        $this->assertSame(1, $fine->getId());
        $this->assertSame('John Tester', $fine->getOffenderName());
        $this->assertSame('Speeding', $fine->getOffenceType());
        $this->assertSame(200.50, $fine->getFineAmount());
        $this->assertSame('2025-10-13', $fine->getDateIssued()->format('Y-m-d'));
        $this->assertSame('2025-10-20', $fine->getDatePaid()->format('Y-m-d'));
        $this->assertSame(FineStatus::PAID->value, $fine->getStatus());
    }

    public function testFineCreationWithNullDatePaid(): void {
        $data = [
            'id' => 2,
            'offender_name' => 'Jane Tester',
            'offence_type' => 'Parking',
            'fine_amount' => 100.00,
            'date_issued' => '2025-10-13',
            'status' => FineStatus::UNPAID->value
        ];

        $fine = new Fine($data);

        $this->assertSame(2, $fine->getId());
        $this->assertSame('Jane Tester', $fine->getOffenderName());
        $this->assertSame('Parking', $fine->getOffenceType());
        $this->assertSame(100.00, $fine->getFineAmount());
        $this->assertSame('2025-10-13', $fine->getDateIssued()->format('Y-m-d'));
        $this->assertNull($fine->getDatePaid());
        $this->assertSame(FineStatus::UNPAID->value, $fine->getStatus());
    }

    public function testFineCreationWithoutId(): void {
        $data = [
            'offender_name' => 'Alice Smith',
            'offence_type' => 'Red Light',
            'fine_amount' => 150.00,
            'date_issued' => '2025-10-14',
        ];

        $fine = new Fine($data);

        $this->assertNull($fine->getId());
        $this->assertSame('Alice Smith', $fine->getOffenderName());
        $this->assertSame('Red Light', $fine->getOffenceType());
        $this->assertSame(150.00, $fine->getFineAmount());
        $this->assertSame('2025-10-14', $fine->getDateIssued()->format('Y-m-d'));
        $this->assertNull($fine->getDatePaid());
        $this->assertSame(FineStatus::UNPAID->value, $fine->getStatus());
    }
}