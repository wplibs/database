<?php

namespace WPLibs\Database;

use wpdb;
use Closure;
use DateTimeInterface;
use Psr\Log\LoggerAwareTrait;
use Database\QueryLogger;
use Database\ConnectionInterface;
use Database\Query\Expression;
use Database\Query\Grammars\Grammar as QueryGrammar;

class Connection implements ConnectionInterface {
	use LoggerAwareTrait;

	/**
	 * The wpdb instance.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;

	/**
	 * The query grammar implementation.
	 *
	 * @var \Database\Query\Grammars\Grammar
	 */
	protected $queryGrammar;

	/**
	 * The default fetch mode of the connection.
	 *
	 * @var int
	 */
	protected $outputMode = ARRAY_A;

	/**
	 * Indicates whether queries are being logged.
	 *
	 * @var bool
	 */
	protected $loggingQueries = false;

	/**
	 * Indicates if the connection is in a "dry run".
	 *
	 * @var bool
	 */
	protected $pretending = false;

	/**
	 * Constructor.
	 *
	 * @param \wpdb             $wpdb
	 * @param QueryGrammar|null $queryGrammar
	 */
	public function __construct( wpdb $wpdb, QueryGrammar $queryGrammar = null ) {
		$this->wpdb         = $wpdb;
		$this->queryGrammar = $queryGrammar ?: new Grammar();
		$this->queryGrammar->setTablePrefix( $wpdb->prefix );
	}

	/**
	 * Get a new query builder instance.
	 *
	 * @return \WPLibs\Database\Builder
	 */
	public function newQuery() {
		return new Builder( $this, $this->getQueryGrammar() );
	}

	/**
	 * Begin a fluent query against a database table.
	 *
	 * @param  string $table
	 *
	 * @return \WPLibs\Database\Builder
	 */
	public function table( $table ) {
		return $this->newQuery()->from( $table );
	}

	/**
	 * Get a new raw query expression.
	 *
	 * @param  mixed $value
	 *
	 * @return \Database\Query\Expression
	 */
	public function raw( $value ) {
		return new Expression( $value );
	}

	/**
	 * Run a select statement against the database.
	 *
	 * @param  string $query
	 * @param  array  $bindings
	 *
	 * @return array
	 */
	public function fetch( $query, array $bindings = [] ) {
		return $this->retrieve( 'get_var', $query, $bindings );
	}

	/**
	 * Run a select statement against the database.
	 *
	 * @param  string $query
	 * @param  array  $bindings
	 *
	 * @return array
	 */
	public function fetchAll( $query, array $bindings = [] ) {
		return $this->retrieve( 'get_results', $query, $bindings );
	}

	/**
	 * Run a select statement and return a single result.
	 *
	 * @param  string $query
	 * @param  array  $bindings
	 *
	 * @return mixed
	 */
	public function fetchOne( $query, array $bindings = [] ) {
		return $this->retrieve( 'get_var', $query, $bindings );
	}

	/**
	 * Run a select statement against the database.
	 *
	 * @param  string $method
	 * @param  string $query
	 * @param  array  $bindings
	 *
	 * @return mixed
	 */
	protected function retrieve( $method, $query, array $bindings = [] ) {
		return $this->run( $query, $bindings, function ( $query, $bindings ) use ( $method ) {
			if ( $this->pretending() ) {
				return [];
			}

			return $this->wpdb->{$method}(
				$this->prepareQuery( $query, $bindings ), $this->outputMode // @codingStandardsIgnoreLine
			);
		} );
	}

	/**
	 * Prepare the query bindings for execution.
	 *
	 * @param  array $bindings
	 *
	 * @return array
	 */
	public function prepareBindings( array $bindings ) {
		$grammar = $this->getQueryGrammar();

		foreach ( $bindings as $key => $value ) {
			// We need to transform all instances of DateTimeInterface into the actual
			// date string. Each query grammar maintains its own date string format
			// so we'll just ask the grammar for the format to get from the date.
			if ( $value instanceof DateTimeInterface ) {
				$bindings[ $key ] = $value->format( $grammar->getDateFormat() );
			} elseif ( is_bool( $value ) ) {
				$bindings[ $key ] = (int) $value;
			}
		}

		return $bindings;
	}

	/**
	 * Execute an SQL statement and return the boolean result.
	 *
	 * @param  string $query
	 * @param  array  $bindings
	 *
	 * @return int|false
	 */
	public function query( $query, array $bindings = [] ) {
		return $this->run( $query, $bindings, function ( $query, $bindings ) {
			if ( $this->pretending() ) {
				return true;
			}

			// @codingStandardsIgnoreLine
			return $this->wpdb->query( $this->prepareQuery( $query, $bindings ) );
		} );
	}

	/**
	 * Return the auto-increment ID of the last inserted row
	 *
	 * @param  mixed $name
	 * @return int
	 */
	public function lastInsertId( $name = null ) {
		return $this->wpdb->insert_id;
	}

	/**
	 * Prepares a SQL query for safe execution.
	 *
	 * @param string $query
	 * @param array  $bindings
	 *
	 * @return string
	 */
	protected function prepareQuery( $query, array $bindings ) {
		if ( false !== strpos( $query, '%' ) && count( $bindings ) > 0 ) {
			// @codingStandardsIgnoreLine
			$query = $this->wpdb->prepare( $query, $this->prepareBindings( $bindings ) );
		}

		return $query;
	}

	/**
	 * Run a SQL statement and log its execution context.
	 *
	 * @param  string   $query
	 * @param  array    $bindings
	 * @param  \Closure $callback
	 *
	 * @return mixed
	 *
	 * @throws \WPLibs\Database\QueryException
	 */
	protected function run( $query, $bindings, Closure $callback ) {
		$start = microtime( true );

		// Prevent showing the errors.
		$suppress_errors = $this->wpdb->suppress_errors;
		$this->wpdb->suppress_errors( true );

		// Here we will run this query. If an exception occurs we'll determine if it was
		// caused by a connection that has been lost. If that is the cause, we'll try
		// to re-establish connection and re-run the query with a fresh connection.
		try {
			$result = $this->runQueryCallback( $query, $bindings, $callback );
		} catch ( \Exception $e ) {
			$result = $this->handleQueryException( $e, $query, $bindings );
		}

		// Once we have run the query we will calculate the time that it took to run and
		// then log the query, bindings, and execution time so we will report them on
		// the event that the developer needs them. We'll log time in milliseconds.
		$this->logQuery( $query, $bindings, $this->getElapsedTime( $start ) );

		$this->wpdb->suppress_errors( $suppress_errors );

		return $result;
	}

	/**
	 * Run a SQL statement.
	 *
	 * @param  string   $query
	 * @param  array    $bindings
	 * @param  \Closure $callback
	 *
	 * @return mixed
	 *
	 * @throws \WPLibs\Database\QueryException
	 */
	protected function runQueryCallback( $query, $bindings, Closure $callback ) {
		try {
			// To execute the statement, we'll simply call the callback, which will actually
			// run the SQL against the connection. Then we can calculate the time it
			// took to execute and log the query SQL, bindings and time in our memory.
			$result = $callback( $query, $bindings );

			$this->maybeThrowDBException();
		} catch ( \Exception $e ) {
			// If an exception occurs when attempting to run a query, we'll format the error
			// message to include the bindings with SQL, which will make this exception a
			// lot more helpful to the developer instead of just the database's errors.
			throw new QueryException( $query, $this->prepareBindings( $bindings ), $e );
		}

		return $result;
	}

	/**
	 * Throw the exception when get any error from $wpdb.
	 *
	 * @throws \RuntimeException
	 */
	protected function maybeThrowDBException() {
		if ( $this->wpdb->last_error ) {
			throw new \RuntimeException( $this->wpdb->last_error );
		}
	}

	/**
	 * Handle a query exception.
	 *
	 * @param  mixed  $e
	 * @param  string $query
	 * @param  array  $bindings
	 * @return mixed
	 *
	 * @throws \WPLibs\Database\QueryException
	 */
	protected function handleQueryException( $e, $query, $bindings ) {
		if ( $result = apply_filters( 'wplibs_database_handle_query_exception', null, $e, $query, $bindings ) ) {
			return $result;
		}

		throw $e;
	}

	/**
	 * Log a query in the connection's query log.
	 *
	 * @param  string     $query
	 * @param  array      $bindings
	 * @param  float|null $time
	 *
	 * @return void
	 */
	protected function logQuery( $query, $bindings, $time = null ) {
		if ( $this->loggingQueries && $this->logger ) {
			$this->logger->debug( $query, compact( 'bindings', 'time' ) );
		}
	}

	/**
	 * Get the elapsed time since a given starting point.
	 *
	 * @param  int $start
	 *
	 * @return float
	 */
	protected function getElapsedTime( $start ) {
		return round( ( microtime( true ) - $start ) * 1000, 2 );
	}

	/**
	 * Execute a Closure within a transaction.
	 *
	 * @param  \Closure $callback
	 *
	 * @return mixed
	 *
	 * @throws \Exception
	 */
	public function transaction( Closure $callback ) {
		$show_error = $this->wpdb->show_errors;

		// Prevent the wpdb errors.
		$this->wpdb->hide_errors();

		$this->beginTransaction();

		try {
			// We'll simply execute the given callback within a try / catch block
			// and if we catch any exception we can rollback the transaction
			// so that none of the changes are persisted to the database.
			$result = $callback( $this );

			$this->commit();
		} catch ( \Exception $e ) {
			// If we catch an exception, we will roll back so nothing gets messed
			// up in the database. Then we'll re-throw the exception so it can
			// be handled how the developer sees fit for their applications.
			$this->rollBack();

			throw $e;
		}

		$this->wpdb->show_errors( $show_error );

		return $result;
	}

	/**
	 * Start a new database transaction.
	 *
	 * @return void
	 */
	public function beginTransaction() {
		$this->wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Commit the active database transaction.
	 *
	 * @return void
	 */
	public function commit() {
		$this->wpdb->query( 'COMMIT' );
	}

	/**
	 * Rollback the active database transaction.
	 *
	 * @return void
	 */
	public function rollBack() {
		$this->wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Checks the connection to see if there is an active transaction
	 *
	 * @return int
	 */
	public function inTransaction() {
		return -1;
	}

	/**
	 * Execute the given callback in "dry run" mode.
	 *
	 * @param  \Closure $callback
	 * @return array
	 */
	public function pretend( Closure $callback ) {
		return $this->withFreshQueryLog( function () use ( $callback ) {
			$this->pretending = true;

			// Basically to make the database connection "pretend", we will just return
			// the default values for all the query methods, then we will return an
			// array of queries that were "executed" within the Closure callback.
			$callback( $this );

			$this->pretending = false;

			return $this->getLogger()->getQueryLog();
		} );
	}

	/**
	 * Determine if the connection in a "dry run".
	 *
	 * @return bool
	 */
	public function pretending() {
		return true === $this->pretending;
	}

	/**
	 * Execute the given callback in "dry run" mode.
	 *
	 * @param  \Closure $callback
	 *
	 * @return array
	 */
	protected function withFreshQueryLog( $callback ) {
		$loggingQueries = $this->loggingQueries;

		// First we will back up the value of the logging queries property and then
		// we'll be ready to run callbacks. This query log will also get cleared
		// so we will have a new log of all the queries that are executed now.
		$this->enableQueryLog();

		$this->getLogger()->flushQueryLog();

		// Now we'll execute this callback and capture the result. Once it has been
		// executed we will restore the value of query logging and give back the
		// value of the callback so the original callers can have the results.
		$result = $callback();

		$this->loggingQueries = $loggingQueries;

		return $result;
	}

	/**
	 * Get the query grammar used by the connection.
	 *
	 * @return \Database\Query\Grammars\Grammar
	 */
	public function getQueryGrammar() {
		return $this->queryGrammar;
	}

	/**
	 * Set the query grammar used by the connection.
	 *
	 * @param  QueryGrammar $grammar
	 * @return $this
	 */
	public function setQueryGrammar( QueryGrammar $grammar ) {
		$this->queryGrammar = $grammar;

		$this->queryGrammar->setTablePrefix( $this->wpdb->prefix );

		return $this;
	}

	/**
	 * Get the logger.
	 *
	 * @return \Psr\Log\LoggerInterface|\Database\QueryLogger
	 */
	public function getLogger() {
		return $this->logger;
	}

	/**
	 * Determine whether we're logging queries.
	 *
	 * @return bool
	 */
	public function logging() {
		return $this->loggingQueries;
	}

	/**
	 * Enable the query log on the connection.
	 *
	 * @return $this
	 */
	public function enableQueryLog() {
		$this->loggingQueries = true;

		if ( ! $this->logger ) {
			$this->setLogger( new QueryLogger );
		}

		return $this;
	}

	/**
	 * Disable the query log on the connection.
	 *
	 * @return $this
	 */
	public function disableQueryLog() {
		$this->loggingQueries = false;

		return $this;
	}
}
