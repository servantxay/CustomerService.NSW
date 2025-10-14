<?php
namespace App;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Class Offender
 *
 * Represents a unique offender (model) in the system.
 * Contains the offender's unique ID, name, and date of birth.
 */
class Offender {
    private ?int $id;
    private string $name;
    private ?DateTimeImmutable $dateOfBirth;
    private const NAME_MAX_LENGTH = 255;

    public function __construct(array $data) {
        // Validdate ID
        $this->id = isset($data['id']) ? filter_var($data['id'], FILTER_VALIDATE_INT) : null;
        if ($this->id !== null && $this->id <= 0) {
            throw new InvalidArgumentException("Invalid offender id");
        }

        // Validdate Name
        $this->name = trim((string)($data['name'] ?? ''));
        if ($this->name === '') {
            throw new InvalidArgumentException("Offender name cannot be empty");
        }
        if (mb_strlen($this->name) > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException("Offender name too long (max " . self::NAME_MAX_LENGTH . " chars)");
        }

        // Validdate Date of birth
        $dob = $data['date_of_birth'] ?? null;
        if ($dob !== null) {
            $this->dateOfBirth = $this->validateDate($dob);
        } else {
            $this->dateOfBirth = null;
        }
    }

    // Getters

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDateOfBirth(): ?DateTimeImmutable { return $this->dateOfBirth;}

    // toArray for safe serialization
    public function toArray(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'date_of_birth' => $this->dateOfBirth?->format('Y-m-d')
        ];
    }

    // Validate a date
    private function validateDate(string $date): DateTimeImmutable {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date_of_birth format, expected YYYY-MM-DD");
        }
        $now = new DateTimeImmutable('today');
        if ($d > $now) {
            throw new InvalidArgumentException("date_of_birth cannot be in the future");
        }
        return $d;
    }
}