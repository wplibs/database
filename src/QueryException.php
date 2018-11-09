<?php

namespace WPLibs\Database;

class QueryException extends \RuntimeException {
	/**
	 * The SQL for the query.
	 *
	 * @var string
	 */
	protected $sql;

	/**
	 * The bindings for the query.
	 *
	 * @var array
	 */
	protected $bindings;

	/**
	 * Create a new query exception instance.
	 *
	 * @param  string     $sql
	 * @param  array      $bindings
	 * @param  \Exception $previous
	 *
	 * @return void
	 */
	public function __construct( $sql, array $bindings, $previous ) {
		parent::__construct( '', 0, $previous );

		$this->sql      = $sql;
		$this->bindings = $bindings;
		$this->previous = $previous;
		$this->code     = $previous->getCode();
		$this->message  = $this->formatMessage( $sql, $bindings, $previous );
	}

	/**
	 * Format the SQL error message.
	 *
	 * @param  string     $sql
	 * @param  array      $bindings
	 * @param  \Exception $previous
	 *
	 * @return string
	 */
	protected function formatMessage( $sql, $bindings, $previous ) {
		foreach ( $bindings as $value ) {
			$sql = preg_replace( '/\?/', $value, $sql, 1 );
		}

		return $previous->getMessage() . ' (SQL: ' . $sql . ')';
	}

	/**
	 * Get the SQL for the query.
	 *
	 * @return string
	 */
	public function getSql() {
		return $this->sql;
	}

	/**
	 * Get the bindings for the query.
	 *
	 * @return array
	 */
	public function getBindings() {
		return $this->bindings;
	}
}
