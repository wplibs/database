WordPress Database [![Build Status](https://travis-ci.org/wplibs/database.svg?branch=master)](https://travis-ci.org/wplibs/database)
==================

## Installation

```
composer require wplibs/database:^1.0
```

## Usage

Basic Example

```php
<?php

use WPLibs\Database\Database;

$builder = Database::newQuery()->select( '*' )->from( 'posts' );

var_dump( $posts = $builder->get() );

var_dump( $builder->toSql() ); // select * from `{$wpdb->posts}`
```

The query above can be shorten by this:

```php
$posts = Database::table( 'posts' )->get();
```
## Documents

https://laravel.com/docs/5.4/queries
