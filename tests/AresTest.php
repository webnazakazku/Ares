<?php

namespace Defr\Ares\Tests;

use Defr\Ares;
use PHPUnit\Framework\TestCase;

final class AresTest extends TestCase
{
    /**
     * @var Ares
     */
    private $ares;

    protected function setUp(): void
    {
        $this->ares = new Ares();
    }

    public function testFindByIdentificationNumber()
    {
        $record = $this->ares->findByIdentificationNumber('73263753');
        $this->assertSame('Dennis Fridrich', $record->getCompanyName());
        $this->assertSame('', $record->getTaxId());
        $this->assertSame('73263753', $record->getCompanyId());
        $this->assertEmpty($record->getStreet());
        $this->assertSame('15', $record->getStreetHouseNumber());
        $this->assertEmpty($record->getStreetOrientationNumber());
        $this->assertSame('Petrovice - Obděnice', $record->getTown());
        $this->assertSame('26255', $record->getZip());
    }

    public function testFindByIdentificationNumberWithLeadingZeros()
    {
        $record = $this->ares->findByIdentificationNumber('00006947');
        $this->assertSame('00006947', $record->getCompanyId());
    }

    public function testFindByIdentificationNumberException()
    {
        $this->expectException(\Defr\Ares\AresException::class);
		$this->ares->findByIdentificationNumber('A1234');
    }

    public function testFindByEmptyStringException()
    {
		$this->expectException(\Defr\Ares\AresException::class);
        $this->ares->findByIdentificationNumber('');
    }

    public function testFindByName()
    {
        $results = $this->ares->findByName('Dennis Fridrich');

        $this->assertGreaterThan(0, count($results));
    }

    public function testFindByNameNonExistentName()
    {
		$this->expectException(\Defr\Ares\AresException::class);
		$this->expectExceptionMessage('Nic nebylo nalezeno.');

		$this->ares->findByName('some non-existent company name');
    }

    public function testGetCompanyPeople()
    {
        if ($this->isCI()) {
            $this->markTestSkipped('Travis cannot connect to Justice.cz');
        }

        $record = $this->ares->findByIdentificationNumber('27791394');
        $companyPeople = $record->getCompanyPeople();

        $this->assertCount(2, $companyPeople);
    }

    public function testBalancer()
    {
		if ($this->isCI()) {
			$this->markTestSkipped('Travis cannot connect to Justice.cz');
		}

        $ares = new Ares();
        $ares->setBalancer('http://some.loadbalancer.domain');
        try {
            $ares->findByIdentificationNumber(26168685);
        } catch (Ares\AresException $e) {
            throw $e;
        }
        $this->assertEquals(
            'http://some.loadbalancer.domain'
            .'?url=http%3A%2F%2Fwwwinfo.mfcr.cz%2Fcgi-bin%2Fares%2Fdarv_bas.cgi%3Fico%3D26168685',
            $ares->getLastUrl()
        );
    }

    /**
     * @return bool
     */
    private function isCI()
    {
        if (getenv('CI')) {
            return true;
        }

        return false;
    }
}
