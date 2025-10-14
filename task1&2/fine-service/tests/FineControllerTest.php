<?php
namespace Tests\App;

/**
 * Unit Test Usage:
 * $ ./vendor/bin/phpunit --testdox tests/FineControllerTest.php
 *
 * Unit Tests for FineController.php
 */

use App\FineController;
use App\FineBusinessService;
use App\FineStatus;
use App\Fine;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class FineControllerTest extends TestCase {
    private Connection $mockConn;
    private QueryBuilder $mockQB;
    private Result $mockResult;

    protected function setUp(): void {
        parent::setUp();

        $this->mockConn = $this->createMock(Connection::class);
        $this->mockQB = $this->createMock(QueryBuilder::class);
        $this->mockResult = $this->createMock(Result::class);
        $this->mockBusinessService = $this->createMock(FineBusinessService::class);

        // Chainable methods for QueryBuilder
        foreach ([
            'select', 'from', 'leftJoin', 'where', 'andWhere',
            'setParameter', 'setParameters', 'insert', 'update',
            'delete', 'set', 'setValue', 'values'
        ] as $method) {
            $this->mockQB->method($method)->willReturnSelf();
        }

        $this->mockQB->method('executeQuery')->willReturn($this->mockResult);
        $this->mockQB->method('executeStatement')->willReturn(1);
        $this->mockConn->method('createQueryBuilder')->willReturn($this->mockQB);

        FineController::setConnection($this->mockConn);
    }

    private function createRequestMock(array $body = []): Request {
        $mockRequest = $this->createMock(Request::class);
        $mockRequest->method('getParsedBody')->willReturn($body);
        return $mockRequest;
    }

    private function createResponseMock(): Response {
        $mockResponse = $this->createMock(Response::class);
        $mockStream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $mockStream->method('write')->willReturnCallback(fn($data) => strlen($data));
        $mockResponse->method('getBody')->willReturn($mockStream);
        $mockResponse->method('withStatus')->willReturnSelf();
        $mockResponse->method('withHeader')->willReturnSelf();
        return $mockResponse;
    }

    private function assertFineData(array $expected, Fine $fine): void {
        $this->assertEquals($expected['fine_amount'], $fine->fine_amount);
        $this->assertEquals($expected['status'], $fine->status);
        $this->assertEquals($expected['offender_id'], $fine->offender_id);
        if (isset($expected['offender_name'])) {
            $this->assertEquals($expected['offender_name'], $fine->offender_name);
        }
        $this->assertIsArray(json_decode($fine->business_flags, true));
    }

    public function testCreateFineSuccess(): void {
        $request = $this->createRequestMock([
            'offender_name' => 'John Tester',
            'offence_type'  => 'speeding',
            'fine_amount'   => 100,
            'date_issued'   => '2025-10-01',
            'status'        => FineStatus::UNPAID->value
        ]);
        $response = $this->createResponseMock();

        $resp = FineController::create($request, $response);
        $this->assertInstanceOf(Response::class, $resp);
    }

    public function testCreateFineNoBody(): void {
        $request = $this->createRequestMock([]);
        $response = $this->createResponseMock();

        $resp = FineController::create($request, $response);
        $this->assertInstanceOf(Response::class, $resp);
    }

    public function testListFinesSuccess(): void {
        $response = $this->createResponseMock();

        $rows = [[
            'id' => 1,
            'offender_id' => 42,
            'offence_type' => 'speeding',
            'fine_amount' => 100,
            'status' => FineStatus::UNPAID->value,
            'date_issued' => '2025-10-01',
            'date_paid' => null,
            'business_flags' => '{}'
        ]];

        $this->mockResult->method('fetchAllAssociative')->willReturn($rows);
        $resp = FineController::list($this->createRequestMock(), $response);
        $this->assertInstanceOf(Response::class, $resp);
    }

    public function testGetFineDataByIdReturnsArray(): void {
        $fineData = [
            'id' => 1,
            'offender_id' => 10,
            'offence_type' => 'Speeding',
            'fine_amount' => 150.00,
            'date_issued' => '2025-10-01',
            'status' => 'unpaid',
            'date_paid' => null,
            'business_flags' => '{}',
            'offender_name' => 'John Doe',
            'offender_dob' => '1990-01-01',
        ];

        // Mock Result to return the fine data
        $mockResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockResult->method('fetchAssociative')->willReturn($fineData);

        // Mock QueryBuilder to return the mock Result
        $mockQB = $this->createMock(\Doctrine\DBAL\Query\QueryBuilder::class);
        $mockQB->method('select')->willReturnSelf();
        $mockQB->method('from')->willReturnSelf();
        $mockQB->method('leftJoin')->willReturnSelf();
        $mockQB->method('where')->willReturnSelf();
        $mockQB->method('setParameter')->willReturnSelf();
        $mockQB->method('executeQuery')->willReturn($mockResult);

        // Mock Connection to return the mock QueryBuilder
        $mockConn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $mockConn->method('createQueryBuilder')->willReturn($mockQB);

        // Inject the mock connection
        \App\FineController::setConnection($mockConn);

        // Mock BusinessService to return the data as-is
        $mockBusinessService = $this->createMock(\App\FineBusinessService::class);
        $mockBusinessService->method('applyBusinessRules')->willReturnCallback(fn($row) => $row);
        \App\FineController::setBusinessService($mockBusinessService);

        // Call the method
        $result = \App\FineController::getFineDataById(1);

        // Assertions
        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Speeding', $result['offence_type']);
        $this->assertSame(150.00, (float)$result['fine_amount']);
        $this->assertSame(10, $result['offender_id']);
        $this->assertSame('John Doe', $result['offender_name']);
        $this->assertSame('1990-01-01', $result['offender_dob']);
    }

    public function testGetFineDataByIdReturnsNullWhenNotFound(): void {
        $this->mockResult
            ->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $method = new \ReflectionMethod(FineController::class, 'getFineDataById');
        $method->setAccessible(true);

        $fine = $method->invoke(null, 999);
        $this->assertNull($fine);
    }

    public function testGetFineSuccess(): void {
        $response = $this->createResponseMock();
        $args = ['id' => 1];

        $this->mockResult->method('fetchAssociative')->willReturn([
            'id' => 1,
            'offender_id' => 42,
            'offence_type' => 'speeding',
            'fine_amount' => 100,
            'status' => FineStatus::UNPAID->value,
            'date_issued' => '2025-10-01',
            'date_paid' => null,
            'business_flags' => '{}'
        ]);

        $resp = FineController::get($this->createRequestMock(), $response, $args);
        $this->assertInstanceOf(Response::class, $resp);
    }

    public function testUpdateFineSuccess(): void {
        $request = $this->createRequestMock(['fine_amount' => 120]);
        $response = $this->createResponseMock();
        $args = ['id' => 1];

        $this->mockResult->method('fetchAssociative')->willReturn([
            'id' => 1,
            'offender_id' => 42,
            'offence_type' => 'speeding',
            'fine_amount' => 100,
            'status' => FineStatus::UNPAID->value,
            'date_issued' => '2025-10-01',
            'date_paid' => null,
            'business_flags' => '{}'
        ]);

        $resp = FineController::update($request, $response, $args);
        $this->assertInstanceOf(Response::class, $resp);
    }

    public function testPartialUpdateSuccess(): void {
        $request = $this->createRequestMock(['fine_amount' => 150]);
        $response = $this->createResponseMock();
        $args = ['id' => 1];

        $this->mockResult->method('fetchAssociative')->willReturn([
            'id' => 1,
            'offender_id' => 42,
            'offence_type' => 'speeding',
            'fine_amount' => 100,
            'status' => FineStatus::UNPAID->value,
            'date_issued' => '2025-10-01',
            'date_paid' => null,
            'business_flags' => '{}'
        ]);

        $resp = FineController::partialUpdate($request, $response, $args);
        $this->assertInstanceOf(Response::class, $resp);
    }

    public function testDeleteFineSuccess(): void {
        $response = $this->createResponseMock();
        $args = ['id' => 1];

        $resp = FineController::delete($this->createRequestMock(), $response, $args);
        $this->assertInstanceOf(Response::class, $resp);
    }

    public function testMarkPaidSuccess(): void {
        $response = $this->createResponseMock();
        $args = ['id' => 1];

        $this->mockResult->method('fetchAssociative')->willReturn([
            'id' => 1,
            'offender_id' => 42,
            'offence_type' => 'speeding',
            'fine_amount' => 100,
            'status' => FineStatus::UNPAID->value,
            'date_issued' => '2025-10-01',
            'date_paid' => null,
            'business_flags' => '{}'
        ]);

        $resp = FineController::markPaid($this->createRequestMock(), $response, $args);
        $this->assertInstanceOf(Response::class, $resp);
    }
}