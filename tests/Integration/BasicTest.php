<?php declare(strict_types=1);

namespace Forrest79\PhPgSql\Tests\Integration;

use Forrest79\PhPgSql\Db;
use Tester;

require_once __DIR__ . '/TestCase.php';

/**
 * @testCase
 */
class BasicTest extends TestCase
{
	/** @var Db\Connection */
	private $connection;


	protected function setUp(): void
	{
		parent::setUp();
		$this->connection = new Db\Connection(\sprintf('%s dbname=%s', $this->getConfig(), $this->getDbName()));
	}


	public function testGetLastErrorWithNoConnection(): void
	{
		Tester\Assert::same('', $this->connection->getLastError());
	}


	public function testPing(): void
	{
		Tester\Assert::true($this->connection->ping());
	}


	public function testConnectedResource(): void
	{
		Tester\Assert::notEqual(NULL, $this->connection->getResource());
	}


	public function testConnectionNoConfig(): void
	{
		$this->connection->setConnectionConfig('');
		Tester\Assert::exception(function (): void {
			$this->connection->connect();
		}, Db\Exceptions\ConnectionException::class, NULL, Db\Exceptions\ConnectionException::NO_CONFIG);
	}


	public function testConnectionForceNew(): void
	{
		$this->connection->setConnectForceNew(TRUE);
		Tester\Assert::true($this->connection->ping());
	}


	public function testConnectionAsync(): void
	{
		$this->connection->setConnectAsync(TRUE);
		$this->connection->setConnectAsyncWaitSeconds(10);
		Tester\Assert::true($this->connection->ping());
	}


	public function testFailedConnection(): void
	{
		$this->connection->setConnectionConfig(\str_replace('user=', 'user=non-existing-user-', $this->getConfig()));
		Tester\Assert::exception(function (): void {
			$this->connection->ping();
		}, Db\Exceptions\ConnectionException::class, NULL, Db\Exceptions\ConnectionException::CONNECTION_FAILED);
	}


	public function testChangeConnectionSettingsAfterConnected(): void
	{
		Tester\Assert::true($this->connection->ping());
		Tester\Assert::exception(function (): void {
			$this->connection->setConnectionConfig('');
		}, Db\Exceptions\ConnectionException::class, NULL, Db\Exceptions\ConnectionException::CANT_CHANGE_CONNECTION_SETTINGS);
		Tester\Assert::exception(function (): void {
			$this->connection->setConnectForceNew(TRUE);
		}, Db\Exceptions\ConnectionException::class, NULL, Db\Exceptions\ConnectionException::CANT_CHANGE_CONNECTION_SETTINGS);
		Tester\Assert::exception(function (): void {
			$this->connection->setConnectAsync(TRUE);
		}, Db\Exceptions\ConnectionException::class, NULL, Db\Exceptions\ConnectionException::CANT_CHANGE_CONNECTION_SETTINGS);
		Tester\Assert::exception(function (): void {
			$this->connection->setConnectAsyncWaitSeconds(5);
		}, Db\Exceptions\ConnectionException::class, NULL, Db\Exceptions\ConnectionException::CANT_CHANGE_CONNECTION_SETTINGS);

		$this->connection->close();

		$this->connection->setConnectionConfig('');
		$this->connection->setConnectForceNew(TRUE);
		$this->connection->setConnectAsync(TRUE);
		$this->connection->setConnectAsyncWaitSeconds(5);
	}


	public function testConnectionEvents(): void
	{
		$connect = FALSE;
		$queryDuration = 0;
		$close = FALSE;

		$this->connection->addOnConnect(static function (Db\Connection $connection) use (&$connect): void {
			$connect = $connection->query('SELECT TRUE')->fetchSingle();
		});

		$this->connection->addOnQuery(static function (Db\Connection $connection, Db\Query $query, float $duration) use (&$queryDuration): void {
			$queryDuration = $duration;
		});

		$this->connection->addOnClose(static function () use (&$close): void {
			$close = TRUE;
		});

		Tester\Assert::same(1, $this->connection->query('SELECT 1')->fetchSingle());

		$this->connection->close();

		Tester\Assert::true($connect);
		Tester\Assert::true($close);
		Tester\Assert::true($queryDuration > 0);
	}


	public function testFailedQuery(): void
	{
		Tester\Assert::exception(function (): void {
			try {
				$this->connection->query('SELECT bad_column');
			} catch (Db\Exceptions\QueryException $e) {
				Tester\Assert::true($e->getQuery() instanceof Db\Query);
				throw $e;
			}
		}, Db\Exceptions\QueryException::class, NULL, Db\Exceptions\QueryException::QUERY_FAILED);
	}


	public function testTransactions(): void
	{
		$this->connection->begin();

		Tester\Assert::true($this->connection->isInTransaction());
		Tester\Assert::same(1, $this->connection->query('SELECT 1')->fetchSingle());

		$this->connection->commit();

		Tester\Assert::false($this->connection->isInTransaction());

		$this->connection->begin();

		Tester\Assert::true($this->connection->isInTransaction());
		Tester\Assert::same(1, $this->connection->query('SELECT 1')->fetchSingle());

		$this->connection->rollback();
	}


	public function testSavepoints(): void
	{
		$this->connection->begin();

		$this->connection->begin('test');

		Tester\Assert::true($this->connection->isInTransaction());
		Tester\Assert::same(1, $this->connection->query('SELECT 1')->fetchSingle());

		$this->connection->commit('test');

		$this->connection->commit();

		Tester\Assert::false($this->connection->isInTransaction());

		$this->connection->begin();

		$this->connection->begin('test');

		Tester\Assert::true($this->connection->isInTransaction());
		Tester\Assert::same(1, $this->connection->query('SELECT 1')->fetchSingle());

		$this->connection->rollback('test');

		$this->connection->rollback();
	}


	public function testPassParamToQuery(): void
	{
		$query = $this->connection->createQuery('SELECT 1');
		Tester\Assert::exception(function () use ($query): void {
			$this->connection->query($query, 1);
		}, Db\Exceptions\QueryException::class, NULL, Db\Exceptions\QueryException::CANT_PASS_PARAMS);
	}


	public function testQueryWithFluentWithParams(): void
	{
		$result = $this->connection->query(
			'SELECT generate_series FROM ?',
			$this->connection::literalArgs('generate_series(?::integer, ?::integer, ?::integer)', [2, 1, -1])
		);

		$rows = $result->fetchAssoc('generate_series');

		Tester\Assert::same(2, \count($rows));

		Tester\Assert::same(2, $rows[2]->generate_series);
		Tester\Assert::same(1, $rows[1]->generate_series);

		$result->free();
	}


	protected function tearDown(): void
	{
		$this->connection->close();
		parent::tearDown();
	}

}

(new BasicTest())->run();
