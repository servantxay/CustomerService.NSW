<?php
namespace App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Doctrine\DBAL\Connection;

/**
 * Class FineController
 *
 * Provides secure CRUD operations for fines.
 * Handles fine creation, retrieval, update, deletion, and marking as paid.
 * Includes input validation, business rules application, and transaction safety.
 */
class FineController {
    private static ?Connection $conn = null;
    private static ?FineBusinessService $businessService = null;

    /*** GETTERS ***/

    private static function getConnection(): Connection {
        if (self::$conn === null) {
            self::$conn = Database::getConnection();
        }
        return self::$conn;
    }

    private static function getBusinessService(): FineBusinessService {
        if (self::$businessService === null) {
            self::$businessService = new FineBusinessService(self::getConnection());
        }
        return self::$businessService;
    }

    /*** SETTERS ***/
    
    public static function setConnection(Connection $connection): void {
        self::$conn = $connection;
        self::$businessService = new FineBusinessService($connection);
    }

    public static function setBusinessService(FineBusinessService $service): void {
        self::$businessService = $service;
    }

    // Return JSON response with proper headers.
    private static function json(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    // Validate a fine status against the FineStatus enum.
    private static function validateStatus(string $status): void {
        try {
            FineStatus::from($status);
        } catch (\ValueError $e) {
            $validStatuses = implode(', ', array_map(fn($c) => $c->value, FineStatus::cases()));
            throw new \InvalidArgumentException("Invalid status '$status'. Valid: $validStatuses");
        }
    }

    // Validate fine input data.
    private static function validateFineData(array $data, bool $requireAllFields = true): void {
        // Required fields
        $required = ['offence_type', 'fine_amount', 'date_issued'];
        if ($requireAllFields) {
            foreach ($required as $f) {
                if (empty($data[$f])) {
                    throw new \InvalidArgumentException("$f is required");
                }
            }
        }

        // Offence type string length
        if (!empty($data['offence_type']) && mb_strlen($data['offence_type']) > 255) {
            throw new \InvalidArgumentException("offence_type max length 255");
        }

        // Fine amount numeric and greater than or equal to zero
        if (isset($data['fine_amount']) && (!is_numeric($data['fine_amount']) || $data['fine_amount'] < 0)) {
            throw new \InvalidArgumentException("fine_amount must be numeric >= 0");
        }

        // Dates
        foreach (['date_issued', 'date_paid'] as $d) {
            if (!empty($data[$d])) {
                $dt = \DateTime::createFromFormat('Y-m-d', $data[$d]);
                if (!$dt || $dt->format('Y-m-d') !== $data[$d]) {
                    throw new \InvalidArgumentException("$d must be YYYY-MM-DD");
                }
            }
        }

        // Status
        if (!empty($data['status'])) {
            self::validateStatus($data['status']);
        }

        // business_flags JSON
        if (isset($data['business_flags'])) {
            if (is_array($data['business_flags'])) {
                $data['business_flags'] = json_encode($data['business_flags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (!is_string($data['business_flags']) || json_decode($data['business_flags']) === null) {
                throw new \InvalidArgumentException("business_flags must be valid JSON or array");
            }
            if (strlen($data['business_flags']) > 1024) {
                throw new \InvalidArgumentException("business_flags exceeds max size 1024");
            }
        }
    }

    // Insert or update an offender and return their ID.
    private static function upsertOffender(string $name, ?string $dob = null): int {
        $conn = self::getConnection();

        // Check if exists
        $existingId = $conn->createQueryBuilder()
            ->select('id')
            ->from('offenders')
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();

        if ($existingId) {
            if ($dob) { // update offenders table
                $conn->createQueryBuilder()
                    ->update('offenders')
                    ->set('date_of_birth', ':dob')
                    ->where('id = :id')
                    ->setParameters(['dob' => $dob, 'id' => $existingId])
                    ->executeStatement();
            }
            return (int)$existingId;
        }

        // Insert new offender
        $conn->createQueryBuilder()
            ->insert('offenders')
            ->setValue('name', ':name')
            ->setValue('date_of_birth', ':dob')
            ->setParameters(['name' => $name, 'dob' => $dob])
            ->executeStatement();

        return (int)$conn->lastInsertId();
    }

    // Persist fine data to the database (update existing fine).
    private static function persistFine(Connection $conn, array $fineData): void {
        if (!isset($fineData['offender_id'])) {
            throw new \InvalidArgumentException("offender_id required");
        }

        $flags = $fineData['business_flags'] ?? '{}';
        $conn->createQueryBuilder()
            ->update('fines')
            ->set('offender_id', ':offender_id')
            ->set('offence_type', ':offence_type')
            ->set('fine_amount', ':fine_amount')
            ->set('date_issued', ':date_issued')
            ->set('status', ':status')
            ->set('date_paid', ':date_paid')
            ->set('business_flags', ':business_flags')
            ->where('id = :id')
            ->setParameters([
                'offender_id'    => $fineData['offender_id'],
                'offence_type'   => $fineData['offence_type'],
                'fine_amount'    => $fineData['fine_amount'],
                'date_issued'    => $fineData['date_issued'],
                'status'         => $fineData['status'],
                'date_paid'      => $fineData['date_paid'] ?? null,
                'business_flags' => $flags,
                'id'             => $fineData['id'],
            ])
            ->executeStatement();
    }

    // Apply business rules to fine data and return Fine model.
    private static function mapFineWithBusinessRules(array $fineData): Fine {
        $fineData = self::getBusinessService()->applyBusinessRules($fineData);
        return new Fine($fineData);
    }

    // get a fine from database with offender_name by id
    public static function getFineDataById(int $fineId): ?array {
        $conn = self::getConnection();

        $qb = $conn->createQueryBuilder();
        $qb->select('f.*, o.name AS offender_name, o.date_of_birth AS offender_dob')
        ->from('fines', 'f')
        ->leftJoin('f', 'offenders', 'o', 'f.offender_id = o.id')
        ->where('f.id = :id')
        ->setParameter('id', $fineId, \PDO::PARAM_INT);

        $stmt = $qb->executeQuery();
        $row = $stmt->fetchAssociative();

        if (!$row) {
            return null;
        }

        // Make sure business service exists
        if (!self::$businessService) {
            self::getBusinessService();
        }

        return self::$businessService->applyBusinessRules($row);
    }

    // Create a new fine. Validates input, ensures offender exists, applies business rules, and inserts the fine in a transaction.
    public static function create(Request $request, Response $response): Response {
        $data = (array)$request->getParsedBody();
        if (empty($data)) {
            return self::json($response, ['error' => 'No fields to create'], 400);
        }

        try {
            self::validateFineData($data);

            $conn = self::getConnection();
            $conn->beginTransaction();

            $data['offender_id'] = self::upsertOffender($data['offender_name'], $data['offender_dob'] ?? null);
            $data['status'] = $data['status'] ?? FineStatus::UNPAID->value;
            $data['date_paid'] = $data['status'] === FineStatus::PAID->value ? date('Y-m-d') : null;
            $data['business_flags'] = $data['business_flags'] ?? '{}';

            // Insert fine
            $conn->createQueryBuilder()
                ->insert('fines')
                ->setValue('offender_id', ':offender_id')
                ->setValue('offence_type', ':offence_type')
                ->setValue('fine_amount', ':fine_amount')
                ->setValue('date_issued', ':date_issued')
                ->setValue('status', ':status')
                ->setValue('date_paid', ':date_paid')
                ->setValue('business_flags', ':business_flags')
                ->setParameters($data)
                ->executeStatement();

            $data['id'] = (int)$conn->lastInsertId();

            self::persistFine($conn, $data);
            $conn->commit();

            return self::json($response, ['success' => true, 'fine' => (new Fine($data))->toArray(), 'message' => 'Fine created successfully']);
        } catch (\Exception $e) {
            if (isset($conn) && $conn->isTransactionActive()) $conn->rollBack();
            return self::json($response, ['error' => $e->getMessage()], 400);
        }
    }

    // List all fines.
    public static function list(Request $request, Response $response): Response {
        try {
            $conn = self::getConnection();
            $rows = $conn->createQueryBuilder()
                ->select('f.*, o.name AS offender_name')
                ->from('fines', 'f')
                ->leftJoin('f', 'offenders', 'o', 'f.offender_id = o.id')
                ->executeQuery()
                ->fetchAllAssociative();

            $fines = array_map(fn($r) => self::mapFineWithBusinessRules($r)->toArray(), $rows);
            return self::json($response, $fines);
        } catch (\Exception $e) {
            return self::json($response, ['error' => $e->getMessage()], 500);
        }
    }

    // Get a single fine by ID.
    public static function get(Request $request, Response $response, array $args): Response {
        try {
            $conn = self::getConnection();
            $row = self::getFineDataById((int)$args['id']);

            if (!$row) return self::json($response, ['error' => 'Fine not found'], 404);

            return self::json($response, self::mapFineWithBusinessRules($row)->toArray());
        } catch (\Exception $e) {
            return self::json($response, ['error' => $e->getMessage()], 500);
        }
    }

    // Update a fine. Supports partial or full update, validates input, persists changes in a transaction.
    public static function update(Request $request, Response $response, array $args): Response {
        $data = (array)$request->getParsedBody();
        if (empty($data)) return self::json($response, ['error' => 'No fields to update'], 400);

        try {
            self::validateFineData($data, false);

            $conn = self::getConnection();
            $conn->beginTransaction();

            $row = self::getFineDataById((int)$args['id']);

            if (!$row) return self::json($response, ['error' => 'Fine not found'], 404);

            if (!empty($data['offender_name'])) {
                $data['offender_id'] = self::upsertOffender($data['offender_name'], $data['offender_dob'] ?? null);
            }

            $updated = array_merge($row, $data);

            if (!empty($updated['status']) && $updated['status'] === FineStatus::PAID->value) {
                $updated['date_paid'] = date('Y-m-d');
            }

            self::persistFine($conn, $updated);
            $conn->commit();

            return self::json($response, ['success' => true, 'fine' => (new Fine($updated))->toArray(), 'message' => 'Fine updated successfully']);
        } catch (\Exception $e) {
            if (isset($conn) && $conn->isTransactionActive()) $conn->rollBack();
            return self::json($response, ['error' => $e->getMessage()], 400);
        }
    }

    // Alias for update, allows partial updates.
    public static function partialUpdate(Request $request, Response $response, array $args): Response {
        // Can reuse update logic since validation allows partial fields
        return self::update($request, $response, $args);
    }

    // Delete a fine by ID.
    public static function delete(Request $request, Response $response, array $args): Response {
        try {
            $conn = self::getConnection();          // Get DB connection
            $fineId = (int)$args['id'];

            // Check if fine exists
            $row = self::getFineDataById($fineId);
            if (!$row) {
                return self::json($response, ['error' => 'Fine not found'], 404);
            }

            // Delete fine
            $conn->createQueryBuilder()
                ->delete('fines')
                ->where('id = :id')
                ->setParameter('id', $fineId, \PDO::PARAM_INT)
                ->executeStatement();

            return self::json($response, ['success' => true, 'message' => 'Fine deleted successfully']);
        } catch (\Exception $e) {
            return self::json($response, ['error' => $e->getMessage()], 500);
        }
    }

    // Mark a fine as paid. Updates the status to PAID and sets the date_paid.
    public static function markPaid(Request $request, Response $response, array $args): Response {
        try {
            $conn = self::getConnection();
            $conn->beginTransaction();

            $row = self::getFineDataById((int)$args['id']);

            if (!$row) return self::json($response, ['error' => 'Fine not found'], 404);

            $row['status'] = FineStatus::PAID->value;
            $row['date_paid'] = date('Y-m-d');

            self::persistFine($conn, $row);
            $conn->commit();

            return self::json($response, ['success' => true, 'fine' => (new Fine($row))->toArray(), 'message' => 'Fine marked as paid']);
        } catch (\Exception $e) {
            if (isset($conn) && $conn->isTransactionActive()) $conn->rollBack();
            return self::json($response, ['error' => $e->getMessage()], 500);
        }
    }

}
