<?php
namespace App;

use App\FineStatus;

/**
 * Class Fine
 *
 * Represents a single fine object (model) issued to an offender.
 * Contains all relevant details such as offender name, offence type, amount, date issued, payment status,
 * and a JSON-encoded field for business rule tracking flags.
 */
class Fine {
    /** @var int|null The unique identifier of the fine (primary key) */
    private ?int $id;

    /** @var int|null Link to unique offender */
    private ?int $offender_id;

    /** @var string The name of the offender */
    private string $offender_name;

    /** @var string The type of offence committed */
    private string $offence_type;

    /** @var float The amount of the fine */
    private float $fine_amount;

    /** @var string The date the fine was issued (YYYY-MM-DD) */
    private \DateTimeImmutable $date_issued;

    /** @var string|null The date the fine was paid (YYYY-MM-DD) */
    private ?\DateTimeImmutable $date_paid;

    /** @var string The status of the fine, e.g., 'unpaid', 'paid', or 'overdue' */
    private string $status;

    /** @var string JSON string tracking which business rules were applied */
    private string $business_flags;

    /**
     * Fine constructor.
     *
     * Initialises a Fine object with the provided data array.
     *
     * @param array<string, mixed> $data Associative array containing fine details:
     *   - 'id' (int|null): optional, the fine ID
     *   - 'offender_name' (string): the name of the offender
     *   - 'offence_type' (string): the offence type
     *   - 'fine_amount' (float|int): the amount of the fine
     *   - 'date_issued' (string): the date issued in 'YYYY-MM-DD' format
     *   - 'date_paid' (string|null): the date paid in 'YYYY-MM-DD' format
     *   - 'status' (string): optional, the status of the fine ('unpaid' by default)
     *   - 'business_flags' (string|array|null): JSON string or array of rule flags (default '{}')
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public function __construct(array $data) {
        $this->id = isset($data['id']) ? $this->validateId($data['id']) : null;
        $this->offender_id = isset($data['offender_id']) ? $this->validateOffenderId($data['offender_id']) : null;
        $this->offender_name = $this->sanitizeString($data['offender_name'] ?? '');
        $this->offence_type = $this->sanitizeString($data['offence_type'] ?? '');
        $this->fine_amount = $this->validateAmount($data['fine_amount'] ?? 0);
        $this->date_issued = $this->validateDate($data['date_issued'] ?? null, 'date_issued');
        $this->date_paid = isset($data['date_paid']) ? $this->validateDate($data['date_paid'], 'date_paid') : null;
        $this->status = $this->validateStatus($data['status'] ?? FineStatus::UNPAID->value);
        $this->setBusinessFlags($data['business_flags'] ?? '{}');
    }

    /*** GETTERS ***/
    public function getId(): ?int { return $this->id; }
    public function getOffenderId(): ?int { return $this->offender_id; }
    public function getOffenderName(): string { return $this->offender_name; }
    public function getOffenceType(): string { return $this->offence_type; }
    public function getFineAmount(): float { return $this->fine_amount; }
    public function getDateIssued(): \DateTimeImmutable { return $this->date_issued; }
    public function getDatePaid(): ?\DateTimeImmutable { return $this->date_paid; }
    public function getStatus(): string { return $this->status; }
    public function getBusinessFlags(): string { return $this->business_flags; }

    /*** SETTERS ***/
    public function setFineAmount(float $amount): void { $this->fine_amount = $this->validateAmount($amount); }
    public function setStatus(string $status): void { $this->status = $this->validateStatus($status); }
    public function setDatePaid(?string $datePaid): void { $this->date_paid = $datePaid ? $this->validateDate($datePaid, 'date_paid') : null; }
    public function setBusinessFlags(array|string $flags): void {
        // Normalise and validate business_flags
        if (is_array($flags)) {
            $this->business_flags = json_encode($flags, JSON_THROW_ON_ERROR);
        } elseif ($this->isValidJson($flags)) {
            $this->business_flags = $flags;
        } else {
            throw new \InvalidArgumentException("Invalid business_flags JSON");
        }
    }

    // Serialize Fine object to Array
    public function toArray(): array {
        return [
            'id' => $this->id,
            'offender_id' => $this->offender_id,
            'offender_name' => $this->offender_name,
            'offence_type' => $this->offence_type,
            'fine_amount' => $this->fine_amount,
            'date_issued' => $this->date_issued?->format('Y-m-d'),
            'date_paid' => $this->date_paid?->format('Y-m-d'),
            'status' => $this->status,
            'business_flags' => $this->business_flags,
        ];
    }

    /*** VALIDATION HELPERS ***/
    
    private function validateId(mixed $id): int {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new \InvalidArgumentException("Invalid id");
        }
        return $id;
    }

    private function validateOffenderId(mixed $offenderId): int {
        $id = filter_var($offenderId, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new \InvalidArgumentException("Invalid offender_id");
        }
        return $id;
    }

    private function validateAmount(mixed $amount): float {
        $amount = filter_var($amount, FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount < 0) {
            throw new \InvalidArgumentException("fine_amount must be non-negative");
        }
        return $amount;
    }

    private function validateStatus(string $status): string {
        $validStatuses = array_map(fn($s) => $s->value, FineStatus::cases());
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException("Invalid status: $status");
        }
        return $status;
    }

    private function validateDate(?string $date, string $field): \DateTimeImmutable {
        if (!$date) {
            throw new \InvalidArgumentException("$field cannot be empty");
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date format for $field");
        }
        return $d;
    }

    /**
     * Sanitize string for security.
     *
     * @param string $str
     * @return string
     */
    private function sanitizeString(string $str): string {
        return htmlspecialchars(trim($str), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Validates whether a string is valid JSON.
     *
     * @param string $json
     * @return bool
     */
    private function isValidJson(string $json): bool {
        json_decode($json);
        return (json_last_error() === JSON_ERROR_NONE);
    }
}