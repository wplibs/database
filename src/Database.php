<?php

namespace WPLibs\Database;

use Database\ConnectionInterface;

/**
 * Main database class.
 *
 * @method static array                                          fetch( $query, array $bindings = [] )
 * @method static array                                          fetchAll( $query, array $bindings = [] )
 * @method static mixed                                          fetchOne( $query, array $bindings = [] )
 * @method static int|false                                      query( $query, array $bindings = [] )
 * @method static mixed                                          transaction( \Closure $callback )
 * @method static array                                          pretend( \Closure $callback )
 * @method static bool                                           pretending()
 * @method static \Psr\Log\LoggerInterface|\Database\QueryLogger getLogger()
 * @method static bool                                           logging()
 * @method static \WPLibs\Database\Connection                    enableQueryLog()
 * @method static \WPLibs\Database\Connection                    disableQueryLog()
 * @method static \WPLibs\Database\Builder                       newQuery()
 *
 * @package WPLibs\WP_Object\Database
 */
class Database {
	/**
	 * The database connection.
	 *
	 * @var \WPLibs\Database\Connection
	 */
	protected static $connection;

	/**
	 * Begin a fluent query against a database table.
	 *
	 * @param  string $table
	 *
	 * @return \WPLibs\Database\Builder
	 */
	public static function table( $table ) {
		return static::getConnection()->table( $table );
	}

	/**
	 * Run a select statement against the database.
	 *
	 * @param  string $query
	 * @param  array  $bindings
	 * @return array
	 */
	public static function select( $query, array $bindings = [] ) {
		return static::getConnection()->fetchAll( $query, $bindings );
	}

	/**
	 * Get the connection instance.
	 *
	 * @return \Database\ConnectionInterface|\WPLibs\Database\Connection
	 */
	public static function getConnection() {
		global $wpdb;

		if ( is_null( static::$connection ) ) {
			static::$connection = new Connection( $wpdb );
		}

		return static::$connection;
	}

	/**
	 * Set the connection implementation.
	 *
	 * @param \Database\ConnectionInterface $connection
	 */
	public static function setConnection( ConnectionInterface $connection ) {
		static::$connection = $connection;
	}

	/**
	 * Handle forward call connection methods.
	 *
	 * @param string $name
	 * @param array  $arguments
	 *
	 * @return mixed
	 */
	public static function __callStatic( $name, $arguments ) {
		if ( method_exists( $connection = static::getConnection(), $name ) ) {
			return $connection->{$name}( ...$arguments );
		}

		throw new \BadMethodCallException( 'Method [' . $name . '] in class [' . get_class( $connection ) . '] does not exist.' );
	}
}
