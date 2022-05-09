# ARES 

[![Build Status](https://badgen.net/github/checks/webnazakazku/ares/master?cache=300)](https://github.com/webnazakazku/ares/actions)
[![Downloads](https://badgen.net/packagist/dm/webnazakazku/ares)](https://packagist.org/packages/webnazakazku/ares)
[![Latest stable](https://badgen.net/packagist/v/webnazakazku/ares)](https://packagist.org/packages/webnazakazku/ares)

Communication with ARES & Justice (Czech business registers).

## Installation

```sh
composer require sunkaflek/ares
```

## Usage

```php
<?php
require __DIR__.'/vendor/autoload.php';

use Sunkaflek\Ares;

$ares = new Ares();

$record = $ares->findByIdentificationNumber(73263753); // instance of AresRecord

$people = $record->getCompanyPeople(); // array of Person
```

## ARES Balancer

You can use simple balance script to spread the traffic among more IP addresses. See script `examples/external.php`.

### Usage

```php
$ares = new Ares();
$ares->setBalancer('http://some.loadbalancer.domain');
```

## Contributors

The list of people who contributed to this library.

 - Dennis Fridrich - @dfridrich
 - Tomáš Votruba - @TomasVotruba
 - Martin Zeman - @Zemistr
 - Jan Kuchař - @jkuchar
 - Petr Parolek - @petrparolek
