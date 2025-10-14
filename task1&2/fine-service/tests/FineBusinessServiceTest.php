<?php
namespace Tests\App;

/**
 * Unit Test Usage:
 * $ ./vendor/bin/phpunit --testdox tests/FineBusinessServiceTest.php
 *
 * Unit Tests for FineBusinessService.php
 */

use App\FineBusinessService;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;

class FineBusinessServiceTest extends TestCase {
    private Connection $mockConn;
    private FineBusinessService $service;

    protected function setUp(): void {
        // Mock Doctrine Connection (no real DB needed)
        $this->mockConn = $this->createMock(Connection::class);

        // Default service using the mock connection
        $this->service = new FineBusinessService($this->mockConn);
    }

    /* ------------------ Success Test Cases ------------------ */

    public function testApplyBusinessRulesInitialisesFlagsProperly(): void {
        $fine = [
            'id' => 1,
            'offender_name' => 'John Tester',
            'offence_type' => 'Speeding',
            'fine_amount' => 200.00,
            'date_issued' => '2025-10-01',
            'status' => 'unpaid',
            'business_flags' => null,
        ];

        $result = $this->service->applyBusinessRules($fine);
        $flags = json_decode($result['business_flags'], true);

        $this->assertIsArray($flags);
        $this->assertArrayHasKey('frequent_offender_surcharge_applied', $flags);
        $this->assertArrayHasKey('overdue_penalty_applied', $flags);
        $this->assertArrayHasKey('early_payment_discount_applied', $flags);
    }

    public function testOverduePenaltyAppliedAfter30Days(): void {
        $fine = [
            'id' => 2,
            'offender_name' => 'Alice',
            'offence_type' => 'Parking Violation',
            'fine_amount' => 100.00,
            'date_issued' => '2025-08-01', // >30 days ago
            'status' => 'unpaid',
            'business_flags' => '{}',
        ];

        $result = $this->service->applyBusinessRules($fine);

        $this->assertEquals('overdue', $result['status']);
        $this->assertEquals(120.00, $result['fine_amount']); // +20% overdue
        $flags = json_decode($result['business_flags'], true);
        $this->assertTrue($flags['overdue_penalty_applied']);
    }

    public function testFrequentOffenderSurchargeAdded(): void {
        // Mock the FineBusinessService to override getUnpaidFineCount()
        $service = $this->getMockBuilder(FineBusinessService::class)
            ->setConstructorArgs([$this->mockConn])
            ->onlyMethods(['getUnpaidFineCount'])
            ->getMock();

        $service->method('getUnpaidFineCount')->willReturn(3);

        $fine = [
            'id' => 3,
            'offender_id' => 42,
            'offender_name' => 'Bob',
            'offence_type' => 'Speeding',
            'fine_amount' => 200.00,
            'date_issued' => (new \DateTime('-5 days'))->format('Y-m-d'),
            'status' => 'unpaid',
            'business_flags' => '{}',
        ];

        $result = $service->applyBusinessRules($fine);

        $this->assertEquals(250.00, $result['fine_amount']); // +$50 surcharge
        $flags = json_decode($result['business_flags'], true);
        $this->assertTrue($flags['frequent_offender_surcharge_applied']);
    }

    public function testEarlyPaymentDiscountWithin14Days(): void {
        $fine = [
            'id' => 4,
            'offender_name' => 'Sarah',
            'offence_type' => 'Speeding',
            'fine_amount' => 300.00,
            'date_issued' => '2025-09-25',
            'date_paid' => '2025-09-30', // paid within 14 days
            'status' => 'paid',
            'business_flags' => '{}',
        ];

        $result = $this->service->applyBusinessRules($fine);

        $this->assertEquals(270.00, $result['fine_amount']); // 10% discount
        $flags = json_decode($result['business_flags'], true);
        $this->assertTrue($flags['early_payment_discount_applied']);
    }

    public function testCombinedRulesAppliedCorrectly(): void {
        // Mock frequent offender count to 3
        $service = $this->getMockBuilder(FineBusinessService::class)
            ->setConstructorArgs([$this->mockConn])
            ->onlyMethods(['getUnpaidFineCount'])
            ->getMock();

        $service->method('getUnpaidFineCount')->willReturn(3);

        $fine = [
            'id' => 6,
            'offender_id' => 42,
            'offender_name' => 'Mark',
            'offence_type' => 'Speeding',
            'fine_amount' => 200.00,
            'date_issued' => (new \DateTime('-45 days'))->format('Y-m-d'), // >30 days ago
            'status' => 'unpaid',
            'business_flags' => '{}',
        ];

        $result = $service->applyBusinessRules($fine);

        // Expected: +20% overdue (240.00) + $50 surcharge = 290.00
        $this->assertEquals(290.00, $result['fine_amount']);
        $this->assertEquals('overdue', $result['status']);
        $flags = json_decode($result['business_flags'], true);
        $this->assertTrue($flags['overdue_penalty_applied']);
        $this->assertTrue($flags['frequent_offender_surcharge_applied']);
    }

    public function testRulesNotDuplicatedWhenAppliedTwice(): void {
        $fine = [
            'id' => 7,
            'offender_name' => 'Frank',
            'offence_type' => 'Speeding',
            'fine_amount' => 200.00,
            'date_issued' => '2025-08-01',
            'status' => 'unpaid',
            'business_flags' => '{}',
        ];

        $firstRun = $this->service->applyBusinessRules($fine);
        $secondRun = $this->service->applyBusinessRules($firstRun);

        $this->assertEquals($firstRun['fine_amount'], $secondRun['fine_amount']);
        $this->assertEquals($firstRun['business_flags'], $secondRun['business_flags']);
    }

    /* ------------------ Failure Test Cases ------------------ */

    public function testInvalidJsonThrowsException(): void {
        $this->expectException(\InvalidArgumentException::class);

        $fine = [
            'id' => 8,
            'offender_name' => 'Chris',
            'offence_type' => 'Speeding',
            'fine_amount' => 100.00,
            'date_issued' => '2025-09-25',
            'status' => 'unpaid',
            'business_flags' => '{invalid-json}', // invalid JSON
        ];

        $this->service->applyBusinessRules($fine);
    }
}