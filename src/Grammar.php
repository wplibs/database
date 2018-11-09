<?php

namespace WPLibs\Database;

use Database\Query\Builder;
use Database\Query\Grammars\MySqlGrammar;

class Grammar extends MySqlGrammar {
	/**
	 * {@inheritdoc}
	 */
	public function parameter( $value ) {
		if ( $this->isExpression( $value ) ) {
			return $this->getValue( $value );
		}

		if ( is_numeric( $value ) ) {
			return is_float( $value ) ? '%f' : '%d';
		}

		return '%s';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function whereBetween( Builder $query, $where ) {
		$between = $where['not'] ? 'not between' : 'between';

		return $this->wrap( $where['column'] ) . ' ' . $between . ' %s and %s';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function compileJoinConstraint( array $clause ) {
		$first = $this->wrap( $clause['first'] );

		$second = $clause['where'] ? '%s' : $this->wrap( $clause['second'] );

		return "{$clause['boolean']} $first {$clause['operator']} $second";
	}
}
