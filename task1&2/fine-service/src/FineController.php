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
    /**
     * @var Connection|null Database connection instance
     */
    private static ?Connection $conn = null;

    /**
     * @var \App\FineBusinessService|null FineBusinessService instance
     */
    private static ?FineBusinessService $businessService = null;

    /*** GETTERS ***/

    /**
     * Get the current database connection, or create a new one if not set
     *
     * @return Connection
     */
    private static function getConnection(): Connection {
        if (self::$conn === null) {
            self::$conn = Database::getConnection();
        }
        return self::$conn;
    }

    /**
     * Get business service and rules
     *
     * @return FineBusinessService
     */
    private static function getBusinessService(): FineBusinessService {
        if (self::$businessService === null) {
            self::$businessService = new FineBusinessService(self::getConnection());
        }
        return self::$businessService;
    }

    /*** SETTERS ***/
    
    /**
     * Set the database connection and initialise the business service
     *
     * @param Connection $connection
     */
    public static function setConnection(Connection $connection): void {
        self::$conn = $connection;
        self::$businessService = new FineBusinessService($connection);
    }

    /**
     * Set the business service and rules
     *
     * @param FineBusinessService $service
     */
    public static function setBusinessService(FineBusinessService $service): void {
        self::$businessService = $service;
    }

    /**
     * Returns a JSON-formatted HTTP response.
     *
     * Encodes the provided data into JSON format and writes it to the response body.
     * The response is then returned with the specified HTTP status code and
     * the `Content-Type` header set to `application/json`.
     *
     * @param Response $response The PSR-7 response object to write the JSON data to.
     * @param mixed    $data     The data to be encoded as JSON.
     * @param int      $status   The HTTP status code for the response (default is 200).
     *
     * @return Response The modified response object containing the JSON-encoded data.
     */
    private static function json(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Validates a fine status value against the FineStatus enum.
     *
     * Checks whether the given status string matches one of the defined FineStatus enum values.
     * If the status is invalid, an InvalidArgumentException is thrown with a list of valid values.
     *
     * @param string $status The fine status string to validate.
     *
     * @throws \InvalidArgumentException If the provided status is not a valid FineStatus value.
     *
     * @return void
     */
    private static function validateStatus(string $status): void {
        try {
            FineStatus::from($status);
        } catch (\ValueError $e) {
            $validStatuses = implode(', ', array_map(fn($c) => $c->value, FineStatus::cases()));
            throw new \InvalidArgumentException("Invalid status '$status'. Valid: $validStatuses");
        }
    }

    /**
     * Validates fine input data for required fields, value types, and format correctness.
     *
     * Ensures that all required fields are present (if $requireAllFields is true) and that
     * values such as offence type, fine amount, and date fields conform to expected rules.
     * Also validates fine status against the FineStatus enum and ensures business flags
     * are valid JSON (or an array convertible to JSON).
     *
     * Validation rules:
     * - `offence_type`: Required (if $requireAllFields is true), must not exceed 255 characters.
     * - `fine_amount`: Must be numeric and ≥ 0.
     * - `date_issued` and `date_paid`: Must follow the 'YYYY-MM-DD' format.
     * - `status`: Must be a valid FineStatus enum value (if provided).
     * - `business_flags`: Must be valid JSON or an array convertible to JSON; max length 1024 characters.
     *
     * @param array $data              The associative array of fine data to validate.
     * @param bool  $requireAllFields  Whether to enforce presence of all required fields (default true).
     *
     * @throws \InvalidArgumentException If any validation rule is violated.
     *
     * @return void
     */
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

        // Validate offence type string length
        if (!empty($data['offence_type']) && mb_strlen($data['offence_type']) > 255) {
            throw new \InvalidArgumentException("offence_type max length 255");
        }

        // Validate fine amount numeric and greater than or equal to zero
        if (isset($data['fine_amount']) && (!is_numeric($data['fine_amount']) || $data['fine_amount'] < 0)) {
            throw new \InvalidArgumentException("fine_amount must be numeric >= 0");
        }

        // Validate dates
        foreach (['date_issued', 'date_paid'] as $d) {
            if (!empty($data[$d])) {
                $dt = \DateTime::createFromFormat('Y-m-d', $data[$d]);
                if (!$dt || $dt->format('Y-m-d') !== $data[$d]) {
                    throw new \InvalidArgumentException("$d must be YYYY-MM-DD");
                }
            }
        }

        // Validate fine status
        if (!empty($data['status'])) {
            self::validateStatus($data['status']);
        }

        // Validate business_flags JSON
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

    /**
     * Inserts or updates an offender record and returns the offender ID.
     *
     * Checks whether an offender with the given name already exists in the database.
     * - If the offender exists, their date of birth is updated (if provided).
     * - If not, a new offender record is created.
     *
     * Returns the offender's ID in either case.
     *
     * @param string      $name The offender’s full name.
     * @param string|null $dob  The offender’s date of birth in 'YYYY-MM-DD' format, or null if unknown.
     *
     * @throws \Doctrine\DBAL\Exception If a database error occurs during query execution.
     *
     * @return int The ID of the existing or newly created offender.
     */
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

    /**
     * Updates an existing fine record in the database.
     *
     * Persists changes to a fine using the provided database connection and fine data.
     * Throws an exception if the offender ID is missing or if the update fails.
     *
     * @param Connection $conn     The database connection instance.
     * @param array      $fineData The associative array of fine data to update.
     *
     * @throws \InvalidArgumentException If the offender_id field is missing.
     * @throws \Doctrine\DBAL\Exception  If a database error occurs during update.
     *
     * @return void
     */
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

    /**
     * Applies business rules to fine data and returns a Fine model.
     *
     * Uses the business service to transform fine data before constructing
     * a Fine object representing the processed result.
     *
     * @param array $fineData The fine data array to apply business rules to.
     *
     * @return Fine The Fine model instance with business rules applied.
     */
    private static function mapFineWithBusinessRules(array $fineData): Fine {
        $fineData = self::getBusinessService()->applyBusinessRules($fineData);
        return new Fine($fineData);
    }

    /**
     * Retrieves a fine record by its ID, including offender details.
     *
     * Joins the fines table with offenders to return offender name and date of birth.
     * Applies business rules before returning the data array.
     *
     * @param int $fineId The unique fine ID to retrieve.
     *
     * @return array|null The fine data with offender details, or null if not found.
     *
     * @throws \Doctrine\DBAL\Exception If a database query error occurs.
     */
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

    /**
     * Creates a new fine record.
     *
     * Validates input data, ensures the offender exists, applies business rules,
     * and inserts the fine in a database transaction. Returns the created fine as JSON.
     *
     * @param Request  $request  The HTTP request containing fine data.
     * @param Response $response The HTTP response object.
     *
     * @return Response A JSON response indicating success or failure.
     */
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

    /**
     * Retrieves and lists all fines.
     *
     * Fetches all fine records from the database, applies business rules,
     * and returns them as a JSON array response.
     *
     * @param Request  $request  The HTTP request object.
     * @param Response $response The HTTP response object.
     *
     * @return Response A JSON response containing the list of fines.
     */
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

    /**
     * Retrieves a single fine by its ID.
     *
     * Fetches a fine record with offender details and applies business rules.
     * Returns a JSON response with the fine data or an error if not found.
     *
     * @param Request  $request  The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array    $args     The route parameters containing the fine ID.
     *
     * @return Response A JSON response with the fine data or an error message.
     */
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

    /**
     * Updates an existing fine record.
     *
     * Supports full or partial updates, validates input data, and persists changes
     * within a transaction. Returns the updated fine data as a JSON response.
     *
     * @param Request  $request  The HTTP request object containing updated fine data.
     * @param Response $response The HTTP response object.
     * @param array    $args     The route parameters containing the fine ID.
     *
     * @return Response A JSON response with the updated fine or an error message.
     */
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

    /**
     * Partially updates an existing fine record.
     *
     * Reuses the update logic but allows partial field validation,
     * enabling selective updates to fine data.
     *
     * @param Request  $request  The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array    $args     The route parameters containing the fine ID.
     *
     * @return Response A JSON response with the updated fine or an error message.
     */
    public static function partialUpdate(Request $request, Response $response, array $args): Response {
        // Can reuse update logic since validation allows partial fields
        return self::update($request, $response, $args);
    }

    /**
     * Deletes a fine record by its ID.
     *
     * Checks whether the fine exists, deletes it from the database,
     * and returns a JSON response confirming successful deletion.
     *
     * @param Request  $request  The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array    $args     The route parameters containing the fine ID.
     *
     * @return Response A JSON response indicating success or failure.
     */
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

    /**
     * Marks a fine as paid.
     *
     * Updates the fine status to PAID, sets the payment date,
     * persists the change within a transaction, and returns the updated fine.
     *
     * @param Request  $request  The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array    $args     The route parameters containing the fine ID.
     *
     * @return Response A JSON response with the updated fine or an error message.
     */
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
