<?php
enum FineStatus: string {
    case PAID = 'paid';
    case UNPAID = 'unpaid';
    case OVERDUE = 'overdue';
}

class FineCalculator {
    private const LATE_PENALTY_RATE = 1.2;
    private const GRACE_PERIOD_DAYS = 30;
    
    // Calculate fine amount based on status and issue date
    public function calcFine(float $amount, string $dateIssued, FineStatus $status): float {
        // Validate amount is positive
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
        
        // Use match expression for status handling
        return match($status) {
            FineStatus::PAID => $amount,
            FineStatus::UNPAID, FineStatus::OVERDUE => $this->calculateWithLateFee($amount, $dateIssued),
        };
    }
    
    // Calculate fine with late fee if overdue
    private function calculateWithLateFee(float $amount, string $dateIssued): float {
        try {
            $issueDate = new DateTimeImmutable($dateIssued); // could be null if using a factory that returns null
            $gracePeriod = self::GRACE_PERIOD_DAYS;
            $dueDate = $issueDate?->modify('+' . $gracePeriod . ' days'); // nullsafe operator
            if ($dueDate === null) {
                throw new InvalidArgumentException('Invalid issue date');
            }

            $currentDate = new DateTimeImmutable();
            return $currentDate > $dueDate ? $amount * self::LATE_PENALTY_RATE : $amount;

        } catch (Exception $e) {
            throw new InvalidArgumentException('Invalid date format: ' . $e->getMessage());
        }
    }
}