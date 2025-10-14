<?php
namespace App;

use Doctrine\DBAL\Connection;

/**
 * Class FineBusinessService
 *
 * Encapsulates the core business rules for calculating fine amounts and statuses.
 * 
 * Applies business rules for fines, like:
 *  - Overdue penalty (20% if >30 days unpaid)
 *  - Frequent offender surcharge (+$50 if offender has >= 3 unpaid fines)
 *  - Early payment discount (10% if paid within 14 days)
 *
 * It keeps track of which rules have been applied using a JSON field "business_flags",
 * so running it multiple times will not double-apply the rules.
 *
 * This class centralises all fine-related logic, so the FineController
 * does not need to be changed when business rules are added or updated.
 */
class FineBusinessService {
    private Connection $conn;

    private const FREQUENT_OFFENDER_THRESHOLD = 3;
    private const FREQUENT_OFFENDER_SURCHARGE = 50.00;
    private const GRACE_PERIOD_DAYS = 30;
    private const OVERDUE_PENALTY_RATE = 0.20;
    private const EARLY_PAYMENT_DAYS = 14;
    private const EARLY_PAYMENT_DISCOUNT_RATE = 0.10;

    public function __construct(Connection $conn) {
        $this->conn = $conn;
    }

    // Get count of unpaid fines for a given offender (parameterized query to prevent SQL injection)
    protected function getUnpaidFineCount(int $offenderId): int {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(id)')
            ->from('fines')
            ->where('offender_id = :offender_id')
            ->andWhere('status != :status')
            ->setParameter('offender_id', $offenderId)
            ->setParameter('status', FineStatus::PAID->value)
            ->executeQuery()
            ->fetchOne();

        return (int)$count;
    }

    // Apply all business rules to a fine
    public function applyBusinessRules(array $fineData): array {
        $fineData = $this->validateFineData($fineData);

        // Normalize business_flags
        $flags = $this->normalizeFlags($fineData['business_flags'] ?? '{}');

        // Apply rules safely
        if (empty($flags['overdue_penalty_applied'])) {
            $fineData = $this->applyOverduePenalty($fineData, $flags);
        }

        if (empty($flags['frequent_offender_surcharge_applied'])) {
            $fineData = $this->applyFrequentOffenderSurcharge($fineData, $flags);
        }

        if (empty($flags['early_payment_discount_applied'])) {
            $fineData = $this->applyEarlyPaymentDiscount($fineData, $flags);
        }

        $fineData['business_flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $fineData;
    }

    // Validate core fine fields
    private function validateFineData(array $fineData): array {
        // fine_amount validation
        if (!isset($fineData['fine_amount']) || !is_numeric($fineData['fine_amount'])) {
            throw new \InvalidArgumentException('Invalid fine_amount');
        }

        // status validation
        if (!isset($fineData['status']) || !in_array($fineData['status'], array_map(fn($s) => $s->value, FineStatus::cases()), true)) {
            throw new \InvalidArgumentException('Invalid status value');
        }

        // dates validation
        foreach (['date_issued', 'date_paid'] as $key) {
            if (!empty($fineData[$key]) && false === strtotime($fineData[$key])) {
                throw new \InvalidArgumentException("Invalid date format for $key");
            }
        }

        // offender_id is optional; only validate if set
        if (isset($fineData['offender_id']) && !is_int($fineData['offender_id'])) {
            throw new \InvalidArgumentException('Invalid offender_id');
        }

        return $fineData;
    }

    // Normalize business_flags to ensure only known keys
    private function normalizeFlags($rawFlags): array {
        $defaults = [
            'frequent_offender_surcharge_applied' => false,
            'overdue_penalty_applied' => false,
            'early_payment_discount_applied' => false,
        ];

        if (is_string($rawFlags)) {
            $decoded = json_decode($rawFlags, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new \InvalidArgumentException('Invalid JSON structure in business_flags');
            }
            $flags = array_intersect_key($decoded, $defaults); // ignore extra keys
        } elseif (is_array($rawFlags)) {
            $flags = array_intersect_key($rawFlags, $defaults);
        } else {
            throw new \InvalidArgumentException('business_flags must be a JSON string or an array');
        }

        return array_merge($defaults, $flags);
    }

    // Apply overdue penalty safely
    private function applyOverduePenalty(array $fine, array &$flags): array {
        if ($fine['status'] === FineStatus::UNPAID->value) {
            $dateIssued = new \DateTimeImmutable($fine['date_issued']);
            $today = new \DateTimeImmutable();
            $daysUnpaid = $dateIssued->diff($today)->days;

            if ($daysUnpaid > self::GRACE_PERIOD_DAYS) {
                $fine['status'] = 'overdue';
                $fine['fine_amount'] = max(0, round($fine['fine_amount'] * (1 + self::OVERDUE_PENALTY_RATE), 2));
                $flags['overdue_penalty_applied'] = true;
            }
        }
        return $fine;
    }

    // Apply frequent offender surcharge safely
    private function applyFrequentOffenderSurcharge(array $fine, array &$flags): array {
        if (!empty($fine['offender_id'])) {
            $unpaidCount = $this->getUnpaidFineCount((int)$fine['offender_id']);

            if ($unpaidCount >= self::FREQUENT_OFFENDER_THRESHOLD) {
                $fine['fine_amount'] = round($fine['fine_amount'] + self::FREQUENT_OFFENDER_SURCHARGE, 2);
                $flags['frequent_offender_surcharge_applied'] = true;
            }
        }
        return $fine;
    }

    // Apply early payment discount safely
    private function applyEarlyPaymentDiscount(array $fine, array &$flags): array {
        if ($fine['status'] === FineStatus::PAID->value && !empty($fine['date_paid'])) {
            $dateIssued = new \DateTimeImmutable($fine['date_issued']);
            $datePaid = new \DateTimeImmutable($fine['date_paid']);
            $daysDiff = $dateIssued->diff($datePaid)->days;

            if ($daysDiff <= self::EARLY_PAYMENT_DAYS) {
                $fine['fine_amount'] = max(0, round($fine['fine_amount'] * (1 - self::EARLY_PAYMENT_DISCOUNT_RATE), 2));
                $flags['early_payment_discount_applied'] = true;
            }
        }
        return $fine;
    }
}