<?php

namespace WPLibs\Database;

class Builder extends \Database\Query\Builder {
	/**
	 * Alias to set the "offset" value of the query.
	 *
	 * @param  int $value
	 * @return $this
	 */
	public function skip( $value ) {
		return $this->offset( $value );
	}

	/**
	 * Alias to set the "limit" value of the query.
	 *
	 * @param  int $value
	 * @return $this
	 */
	public function take( $value ) {
		return $this->limit( $value );
	}

	/**
	 * Execute the query as a "select" statement.
	 *
	 * @param  array $columns
	 * @return array
	 */
	public function get( $columns = [ '*' ] ) {
		return $this->onceWithColumns( $columns, function () {
			return $this->connection->fetchAll( $this->toSql(), $this->getBindings() );
		} );
	}

	/**
	 * Insert a new record into the database.
	 *
	 * @param  array $values
	 * @return bool
	 */
	public function insert( array $values ) {
		if ( empty( $values ) ) {
			return false;
		}

		return (bool) parent::insert( $values );
	}

	/**
	 * Update a record in the database.
	 *
	 * @param  array $values
	 * @return int
	 */
	public function update( array $values ) {
		return (int) parent::update( $values );
	}

	/**
	 * Increment a column's value by a given amount.
	 *
	 * @param  string $column
	 * @param  int    $amount
	 * @param  array  $extra
	 * @return int
	 */
	public function increment( $column, $amount = 1, array $extra = [] ) {
		if ( ! is_numeric( $amount ) ) {
			throw new \InvalidArgumentException( 'Non-numeric value passed to increment method.' );
		}

		return (int) parent::increment( $column, $amount, $extra );
	}

	/**
	 * Decrement a column's value by a given amount.
	 *
	 * @param  string $column
	 * @param  int    $amount
	 * @param  array  $extra
	 * @return int
	 */
	public function decrement( $column, $amount = 1, array $extra = [] ) {
		if ( ! is_numeric( $amount ) ) {
			throw new \InvalidArgumentException( 'Non-numeric value passed to decrement method.' );
		}

		return (int) parent::decrement( $column, $amount, $extra );
	}

	/**
	 * Delete a record from the database.
	 *
	 * @param  mixed $id
	 * @return int
	 */
	public function delete( $id = null ) {
		return (int) parent::delete( $id );
	}

	/**
	 * Execute a callback over each item while chunking.
	 *
	 * @param  callable $callback
	 * @param  int      $count
	 * @return bool
	 */
	public function each( callable $callback, $count = 1000 ) {
		return $this->chunk( $count, function ( $results ) use ( $callback ) {
			foreach ( $results as $key => $value ) {
				if ( $callback( $value, $key ) === false ) {
					return false;
				}
			}

			return true;
		} );
	}

	/**
	 * Chunk the results of the query.
	 *
	 * @param  int      $count
	 * @param  callable $callback
	 * @return bool
	 */
	public function chunk( $count, callable $callback ) {
		$this->enforceOrderBy();

		$page = 1;

		do {
			// We'll execute the query for the given page and get the results. If there are
			// no results we can just break and return from here. When there are results
			// we will call the callback with the current chunk of these results here.
			$results = $this->forPage( $page, $count )->get();

			$countResults = count( $results );

			if ( 0 === $countResults ) {
				break;
			}

			// On each chunk result set, we will pass them to the callback and then let the
			// developer take care of everything within the callback, which allows us to
			// keep the memory low for spinning through large result sets for working.
			if ( $callback( $results, $page ) === false ) {
				return false;
			}

			unset( $results );

			$page ++;
		} while ( $countResults === $count );

		return true;
	}

	/**
	 * Throw an exception if the query doesn't have an orderBy clause.
	 *
	 * @return void
	 * @throws \RuntimeException
	 */
	protected function enforceOrderBy() {
		if ( empty( $this->orders ) ) {
			throw new \RuntimeException( 'You must specify an orderBy clause when using this function.' );
		}
	}

	/**
	 * Execute the given callback while selecting the given columns.
	 *
	 * After running the callback, the columns are reset to the original value.
	 *
	 * @param  array    $columns
	 * @param  callable $callback
	 *
	 * @return mixed
	 */
	protected function onceWithColumns( $columns, $callback ) {
		$original = $this->columns;

		if ( is_null( $original ) ) {
			$this->columns = $columns;
		}

		$result = $callback();

		$this->columns = $original;

		return $result;
	}
}
