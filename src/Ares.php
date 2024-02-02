<?php

namespace Sunkaflek;

use Defr\Lib;
use Sunkaflek\Ares\AresException;
use Sunkaflek\Ares\AresRecord;
use Sunkaflek\Ares\AresRecords;
use Sunkaflek\Ares\TaxRecord;
use InvalidArgumentException;

if (!function_exists('str_starts_with')) {
	function str_starts_with($haystack, $needle) {
		return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
	}
}

if (!function_exists('str_ends_with')) {
	function str_ends_with($haystack, $needle) {
		return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
	}
}
if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle) {
		return $needle !== '' && mb_strpos($haystack, $needle) !== false;
	}
}
/**
 * Class Ares.
 *
 * @author Dennis Fridrich <fridrich.dennis@gmail.com>
 */
class Ares
{

    const URL_BAS = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/%s';

    const URL_RES = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_res.cgi?ICO=%s';

    const URL_TAX = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/ares_es.cgi?ico=%s&filtr=0';

    const URL_FIND = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/ares_es.cgi?obch_jm=%s&obec=%s&filtr=0';

    /**
     * @var string
     */
    private $cacheStrategy = 'YW';

    /**
     * @var string
     */
    private $cacheDir = null;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var string
     */
    private $balancer = null;

    /**
     * @var array
     */
    private $contextOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    /**
     * @var string
     */
    private $lastUrl;

    /**
     * @param null $cacheDir
     * @param bool $debug
     */
    public function __construct($cacheDir = null, $debug = false, $balancer = null)
    {
        if (null === $cacheDir) {
            $cacheDir = sys_get_temp_dir();
        }

        if (null !== $balancer) {
            $this->balancer = $balancer;
        }

        $this->cacheDir = $cacheDir . '/ares';
        $this->debug = $debug;

        // Create cache dirs if they doesn't exist
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @param string $balancer
     *
     * @return $this
     */
    public function setBalancer($balancer)
    {
        $this->balancer = $balancer;

        return $this;
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function wrapUrl($url)
    {
        if ($this->balancer) {
            $url = sprintf('%s?url=%s', $this->balancer, urlencode($url));
        }

        $this->lastUrl = $url;

        return $url;
    }

    /**
     * @return string
     */
    public function getLastUrl()
    {
        return $this->lastUrl;
    }

    /**
     * @param $id
     *
     * @return AresRecord
     * @throws Ares\AresException
     *
     * @throws InvalidArgumentException
     */
    public function findByIdentificationNumber($id)
    {
        $this->ensureIdIsInteger($id);

        if (empty($id)) {
            throw new AresException('IČ firmy musí být zadáno.');
        }

        $cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
        $cachedFile = $this->cacheDir . '/bas_' . $cachedFileName;
        $cachedRawFile = $this->cacheDir . '/bas_raw_' . $cachedFileName;

        if (is_file($cachedFile)) {
            return unserialize(file_get_contents($cachedFile));
        }

        // Sestaveni URL
        $url = $this->wrapUrl(sprintf(self::URL_BAS, $id));

        try {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

			$aresRequest = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			curl_close($ch);

			if ($httpCode >= 400) {
				if ($httpCode === 404) {
					throw new AresException('IČ firmy nebylo nalezeno.');
				}

				try {
					$aresResponse = json_decode($aresRequest, FALSE, 512, JSON_THROW_ON_ERROR);
					throw new AresException(sprintf('Databáze ARES není dostupná. Zpráva: %s - %s - %s', $aresResponse->kod, $aresResponse->popis, $aresResponse->subKod));
				} catch (AresException $e) {
					throw $e;
				} catch (\Exception $e) {
					throw new AresException(sprintf('Databáze ARES není dostupná. Zpráva: %s', $e->getMessage()));
				}
			}

            //$aresRequest = file_get_contents($url, false, stream_context_create($this->contextOptions));
            if ($this->debug) {
                file_put_contents($cachedRawFile, $aresRequest);
            }

			$aresResponse = json_decode($aresRequest, FALSE, 512);
            if ($aresResponse) {
                $ico = $aresResponse->ico ?: null;
                if ($ico !== $id) {
                    throw new AresException('IČ firmy nebylo nalezeno.');
                }

                $record = new AresRecord();

                $record->setCompanyId($ico);
                $record->setTaxId($aresResponse->dic ?? '');
                
                $obchodniJmeno = $aresResponse->obchodniJmeno;
                if (substr($obchodniJmeno, 0, 1) === '"' AND substr($obchodniJmeno, -1) ==='"') {
                    $obchodniJmeno = substr($obchodniJmeno,1,-1);
                }
                    
				$record->setCompanyName($obchodniJmeno);

				$street = $aresResponse->sidlo->nazevUlice ?? '';
				if ($street === '') {
					$street = $aresResponse->sidlo->nazevCastiObce ?? '';
				}
                if ($street === '') {
                    $street = $aresResponse->sidlo->nazevObce ?? '';
                }

                $cisloDomovni = $aresResponse->sidlo->cisloDomovni ?? '';
                if (!$cisloDomovni) $cisloDomovni = $aresResponse->sidlo->cisloDoAdresy ?? '';

				$record->setStreet($street);
				$record->setStreetHouseNumber($cisloDomovni);
                
                $cisloOrientacni = $aresResponse->sidlo->cisloOrientacni ?? '';
                if (isset($aresResponse->sidlo->cisloOrientacniPismeno)) $cisloOrientacni .= $aresResponse->sidlo->cisloOrientacniPismeno;

				$record->setStreetOrientationNumber($cisloOrientacni);
				$town = $aresResponse->sidlo->nazevObce ?? '';

				if ($town === 'Praha') {
					if (isset($aresResponse->sidlo->nazevMestskeCastiObvodu)) {
						$town = $aresResponse->sidlo->nazevMestskeCastiObvodu;
						if (str_contains($town, '-')) {
							$town = $aresResponse->sidlo->nazevMestskehoObvodu ?? $town;
						}
						$townPart = $aresResponse->sidlo->nazevCastiObce ?? NULL;
						if ($townPart !== NULL && !str_contains($town, $townPart)) {
							$town .= ' - ' . $townPart;
						}
					} else {
						$town = $aresResponse->sidlo->nazevMestskehoObvodu ?? $town;
					}
				} else {
					$townPart = $aresResponse->sidlo->nazevCastiObce ?? NULL;
					if ($townPart !== NULL && $townPart !== $town) {
						$townPartContainsTown = FALSE;
                        $townContainsPart = FALSE;
						if (str_starts_with($townPart, $town)) {
							$townPartContainsTown = TRUE;
						}
                        if (str_contains($town, $townPart)) {
                            $townContainsPart = TRUE;
                        }
						if ($townPartContainsTown) {
							$town = $townPart;
                        } else if ($townContainsPart) {
                            $town = $town; // no change
						} else {
							$town .= ' - ' . $townPart;
						}
					}
				}

				$record->setTown($town);
				$record->setZip((string)($aresResponse->sidlo->psc ?? $aresResponse->sidlo->pscTxt ?? ''));

				if (isset($aresResponse->sidlo->textovaAdresa) && $record->getZip() === '' && $record->getTown() === '' && trim($record->getStreet()) === '') {
					$re = '/([a-zA-ZěščřžýáíéĚŠČŘŽÝÁÍÉ ]+), (.*), PSČ ([0-9 ]+), /mUu';
					preg_match($re, $aresResponse->sidlo->textovaAdresa, $matches);

					if ($matches !== []) {
						$record->setStreet($matches[2]);
						$record->setTown($matches[1]);
						$record->setZip(str_replace(' ', '', $matches[3]));
					} else {
						$re = '/([0-9 ]+) ([a-zA-ZěščřžýáíéĚŠČŘŽÝÁÍÉ 0-9-]+), /mUu';
						preg_match($re, $aresResponse->sidlo->textovaAdresa, $matches);

						if ($matches !== []) {
							$record->setStreet($matches[2]);
							$record->setTown($matches[2]);
							$record->setZip(str_replace(' ', '', $matches[1]));
						}
					}
				}
            } else {
                throw new AresException('Databáze ARES není dostupná.');
            }
        } catch (\Exception $e) {
            throw new AresException($e->getMessage());
        }

        file_put_contents($cachedFile, serialize($record));

        return $record;
    }

    /**
     * @param $id
     *
     * @return AresRecord
     * @throws Ares\AresException
     *
     * @throws InvalidArgumentException
     */
    public function findInResById($id)
    {
        $id = Lib::toInteger($id);
        $this->ensureIdIsInteger($id);

        // Sestaveni URL
        $url = $this->wrapUrl(sprintf(self::URL_RES, $id));

        $cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
        $cachedFile = $this->cacheDir . '/res_' . $cachedFileName;
        $cachedRawFile = $this->cacheDir . '/res_raw_' . $cachedFileName;

        if (is_file($cachedFile)) {
            return unserialize(file_get_contents($cachedFile));
        }

        try {
            $aresRequest = file_get_contents($url, null, stream_context_create($this->contextOptions));
            if ($this->debug) {
                file_put_contents($cachedRawFile, $aresRequest);
            }
            $aresResponse = simplexml_load_string($aresRequest);

            if ($aresResponse) {
                $ns = $aresResponse->getDocNamespaces();
                $data = $aresResponse->children($ns['are']);
                $elements = $data->children($ns['D'])->Vypis_RES;

                if (strval($elements->ZAU->ICO) === $id) {
                    $record = new AresRecord();
                    $record->setCompanyId(strval($id));
                    $record->setTaxId($this->findVatById($id));
                    $record->setCompanyName(strval($elements->ZAU->OF));
                    $record->setStreet(strval($elements->SI->NU));
                    $record->setStreetHouseNumber(strval($elements->SI->CD));
                    $record->setStreetOrientationNumber(strval($elements->SI->CO));
                    $record->setTown(strval($elements->SI->N));
                    $record->setZip(strval($elements->SI->PSC));
                } else {
                    throw new AresException('IČ firmy nebylo nalezeno.');
                }
            } else {
                throw new AresException('Databáze ARES není dostupná.');
            }
        } catch (\Exception $e) {
            throw new AresException($e->getMessage());
        }
        file_put_contents($cachedFile, serialize($record));

        return $record;
    }

    /**
     * @param $id
     *
     * @return string
     * @throws \Exception
     *
     * @throws InvalidArgumentException
     */
    public function findVatById($id)
    {
        $id = Lib::toInteger($id);

        $this->ensureIdIsInteger($id);

        // Sestaveni URL
        $url = $this->wrapUrl(sprintf(self::URL_TAX, $id));

        $cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
        $cachedFile = $this->cacheDir . '/tax_' . $cachedFileName;
        $cachedRawFile = $this->cacheDir . '/tax_raw_' . $cachedFileName;

        if (is_file($cachedFile)) {
            return unserialize(file_get_contents($cachedFile));
        }

        try {
            $vatRequest = file_get_contents($url, null, stream_context_create($this->contextOptions));
            if ($this->debug) {
                file_put_contents($cachedRawFile, $vatRequest);
            }
            $vatResponse = simplexml_load_string($vatRequest);

            if ($vatResponse) {
                $record = new TaxRecord();
                $ns = $vatResponse->getDocNamespaces();
                $data = $vatResponse->children($ns['are']);
                $elements = $data->children($ns['dtt'])->V->S;

                if (strval($elements->ico) === $id) {
                    $record->setTaxId(str_replace('dic=', 'CZ', strval($elements->p_dph)));
                } else {
                    throw new AresException('DIČ firmy nebylo nalezeno.');
                }
            } else {
                throw new AresException('Databáze MFČR není dostupná.');
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        file_put_contents($cachedFile, serialize($record));

        return $record;
    }

    /**
     * @param string $name
     * @param null   $city
     *
     * @return array|AresRecord[]|AresRecords
     * @throws AresException
     *
     * @throws InvalidArgumentException
     */
    public function findByName($name, $city = null)
    {
        if (strlen($name) < 3) {
            throw new InvalidArgumentException('Zadejte minimálně 3 znaky pro hledání.');
        }

        $url = $this->wrapUrl(sprintf(
            self::URL_FIND,
            urlencode(Lib::stripDiacritics($name)),
            urlencode(Lib::stripDiacritics($city))
        ));

        $cachedFileName = date($this->cacheStrategy) . '_' . md5($name . $city) . '.php';
        $cachedFile = $this->cacheDir . '/find_' . $cachedFileName;
        $cachedRawFile = $this->cacheDir . '/find_raw_' . $cachedFileName;

        if (is_file($cachedFile)) {
            return unserialize(file_get_contents($cachedFile));
        }

        $aresRequest = file_get_contents($url, null, stream_context_create($this->contextOptions));
        if ($this->debug) {
            file_put_contents($cachedRawFile, $aresRequest);
        }
        $aresResponse = simplexml_load_string($aresRequest);
        if (!$aresResponse) {
            throw new AresException('Databáze ARES není dostupná.');
        }

        $ns = $aresResponse->getDocNamespaces();
        $data = $aresResponse->children($ns['are']);
        $elements = $data->children($ns['dtt'])->V->S;

        if (empty($elements)) {
            throw new AresException('Nic nebylo nalezeno.');
        }

        $records = new AresRecords();
        foreach ($elements as $element) {
            $record = new AresRecord();
            $record->setCompanyId(strval($element->ico));
            $record->setTaxId(
                ($element->dph ? str_replace('dic=', 'CZ', strval($element->p_dph)) : '')
            );
            $record->setCompanyName(strval($element->ojm));
            //'adresa' => strval($element->jmn));
            $records[] = $record;
        }
        file_put_contents($cachedFile, serialize($records));

        return $records;
    }

    /**
     * @param string $cacheStrategy
     */
    public function setCacheStrategy($cacheStrategy)
    {
        $this->cacheStrategy = $cacheStrategy;
    }

    /**
     * @param bool $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * @param int $id
     */
    private function ensureIdIsInteger($id)
    {
        if (!is_numeric($id)) {
            throw new InvalidArgumentException('IČ firmy musí být číslo.');
        }
    }
}
