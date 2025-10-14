<?php

/**
 * Usage: php FineCalculatorTest.php
 */

require_once 'FineCalculator.php';

class FineCalculatorTest {
    private FineCalculator $calculator;
    private array $results = [];
    
    public function __construct() {
        $this->calculator = new FineCalculator();
    }
    
    public function runAllTests(): void {
        echo "=== Fine Calculator Tests ===\n\n";
        
        $this->testPaidStatus();
        $this->testUnpaidWithinGracePeriod();
        $this->testUnpaidAfterGracePeriod();
        $this->testOverdueStatus();
        $this->testNegativeAmount();
        $this->testInvalidDateFormat();
        $this->testEdgeCases();
        
        $this->printSummary();
    }
    
    private function testPaidStatus(): void {
        echo "Test 1: PAID status (no overdue fee)\n";
        try {
            $result = $this->calculator->calcFine(100.0, '2024-10-14', FineStatus::PAID);
            $this->assert($result === 100.0, "Expected 100.0, got {$result}");
        } catch (Exception $e) {
            $this->fail("Exception thrown: " . $e->getMessage());
        }
        echo "\n";
    }
    
    private function testUnpaidWithinGracePeriod(): void {
        echo "Test 2: UNPAID status within grace period\n";
        try {
            // Date within 30 days
            $recentDate = (new DateTimeImmutable())->modify('-15 days')->format('Y-m-d');
            $result = $this->calculator->calcFine(100.0, $recentDate, FineStatus::UNPAID);
            $this->assert($result === 100.0, "Expected 100.0 (no penalty), got {$result}");
        } catch (Exception $e) {
            $this->fail("Exception thrown: " . $e->getMessage());
        }
        echo "\n";
    }
    
    private function testUnpaidAfterGracePeriod(): void {
        echo "Test 3: UNPAID status after grace period (overdue fee applied)\n";
        try {
            // Date more than 30 days ago
            $oldDate = (new DateTimeImmutable())->modify('-45 days')->format('Y-m-d');
            $result = $this->calculator->calcFine(100.0, $oldDate, FineStatus::UNPAID);
            $expected = 120.0; // 100 * 1.2
            $this->assert($result === $expected, "Expected {$expected}, got {$result}");
        } catch (Exception $e) {
            $this->fail("Exception thrown: " . $e->getMessage());
        }
        echo "\n";
    }
    
    private function testOverdueStatus(): void {
        echo "Test 4: OVERDUE status with overdue penalty\n";
        try {
            $oldDate = (new DateTimeImmutable())->modify('-60 days')->format('Y-m-d');
            $result = $this->calculator->calcFine(250.0, $oldDate, FineStatus::OVERDUE);
            $expected = 300.0; // 250 * 1.2
            $this->assert($result === $expected, "Expected {$expected}, got {$result}");
        } catch (Exception $e) {
            $this->fail("Exception thrown: " . $e->getMessage());
        }
        echo "\n";
    }
    
    private function testNegativeAmount(): void {
        echo "Test 5: Negative amount (should throw exception)\n";
        try {
            $this->calculator->calcFine(-50.0, '2024-10-14', FineStatus::PAID);
            $this->fail("Expected InvalidArgumentException to be thrown");
        } catch (InvalidArgumentException $e) {
            $this->assert(true, "Exception thrown as expected: " . $e->getMessage());
        } catch (Exception $e) {
            $this->fail("Wrong exception type: " . $e->getMessage());
        }
        echo "\n";
    }
    
    private function testInvalidDateFormat(): void {
        echo "Test 6: Invalid date format (should throw exception)\n";
        try {
            $this->calculator->calcFine(100.0, 'invalid-date', FineStatus::UNPAID);
            $this->fail("Expected InvalidArgumentException to be thrown");
        } catch (InvalidArgumentException $e) {
            $this->assert(true, "Exception thrown as expected: " . $e->getMessage());
        } catch (Exception $e) {
            $this->fail("Wrong exception type: " . $e->getMessage());
        }
        echo "\n";
    }
    
    private function testEdgeCases(): void {
        echo "Test 7: Edge cases\n";
        
        // Test with zero amount
        try {
            $result = $this->calculator->calcFine(0.0, '2024-10-14', FineStatus::PAID);
            $this->assert($result === 0.0, "Zero amount: Expected 0.0, got {$result}");
        } catch (Exception $e) {
            $this->fail("Zero amount exception: " . $e->getMessage());
        }
        
        // Test exactly 30 days (boundary)
        try {
            $boundaryDate = (new DateTimeImmutable())->modify('-30 days')->format('Y-m-d');
            $result = $this->calculator->calcFine(100.0, $boundaryDate, FineStatus::UNPAID);
            echo "  - Boundary test (30 days): Result = {$result}\n";
        } catch (Exception $e) {
            $this->fail("Boundary test exception: " . $e->getMessage());
        }
        
        // Test with decimal amounts
        try {
            $oldDate = (new DateTimeImmutable())->modify('-45 days')->format('Y-m-d');
            $result = $this->calculator->calcFine(99.99, $oldDate, FineStatus::OVERDUE);
            $expected = 119.988; // 99.99 * 1.2
            $this->assert(abs($result - $expected) < 0.01, "Decimal amount: Expected {$expected}, got {$result}");
        } catch (Exception $e) {
            $this->fail("Decimal amount exception: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function assert(bool $condition, string $message): void {
        if ($condition) {
            echo "  PASS: {$message}\n";
            $this->results[] = true;
        } else {
            echo "  FAIL: {$message}\n";
            $this->results[] = false;
        }
    }
    
    private function fail(string $message): void {
        echo "  FAIL: {$message}\n";
        $this->results[] = false;
    }
    
    private function printSummary(): void {
        $total = count($this->results);
        $passed = count(array_filter($this->results));
        $failed = $total - $passed;
        
        echo "=================================\n";
        echo "Test Summary:\n";
        echo "  Total:  {$total}\n";
        echo "  Passed: {$passed}\n";
        echo "  Failed: {$failed}\n";
        echo "=================================\n";
    }
}

// Run the tests
$tester = new FineCalculatorTest();
$tester->runAllTests();