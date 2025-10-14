<?php
enum FineStatus: string {
    case PAID = 'paid';
    case UNPAID = 'unpaid';
    case OVERDUE = 'overdue';
}

class FineCalculator {
    private const LATE_PENALTY_RATE = 1.2;
    private const GRACE_PERIOD_DAYS = 30;
    
    /**
     * Calculate fine amount based on status and issue date
     */
    public function calcFine(float $amount, string $dateIssued, FineStatus $status): float {
        // Validate amount is positive
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount must be a positive number');
        }
        
        return match ($status) {
            FineStatus::PAID => $amount,
            FineStatus::UNPAID, FineStatus::OVERDUE => $this->calculateWithOverdueFee($amount, $dateIssued),
        };
    }
    
    /**
     * Calculate fine with late fee if overdue
     */
    private function calculateWithOverdueFee(float $amount, string $dateIssued): float {
        try {
            $issueDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateIssued);

            if (!$issueDate instanceof DateTimeImmutable || !($issueDate && $issueDate->format('Y-m-d') === $dateIssued)) {
                throw new InvalidArgumentException('Invalid date format');
            }

            $dueDate = $issueDate?->modify('+' . self::GRACE_PERIOD_DAYS . ' days');
            $currentDate = new DateTimeImmutable();
            
            // Return late fee or original amount
            return $currentDate > $dueDate ? $amount * self::LATE_PENALTY_RATE : $amount;
                
        } catch (Exception $e) {
            throw new InvalidArgumentException('Invalid date format: '.$e->getMessage());
        }
    }
}