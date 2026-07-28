<?php
namespace MysqlToGoogleBigQuery\Tests\Config;

use MysqlToGoogleBigQuery\Config\ConfigReaderInterface;

/**
 * In-memory reader standing in for Secret Manager / Parameter Manager: the
 * generated clients are final and cannot be doubled, and unit tests must not
 * reach any real API.
 */
class FakeConfigReader implements ConfigReaderInterface
{
    public int $reads = 0;

    /**
     * @param string   $scheme URI scheme served by this reader, without "://"
     * @param string[] $values Values keyed by full URI
     */
    public function __construct(private string $scheme, private array $values = [])
    {
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function read(string $uri): string
    {
        $this->reads++;

        if (!array_key_exists($uri, $this->values)) {
            throw new \RuntimeException('NOT_FOUND: ' . $uri);
        }

        return $this->values[$uri];
    }
}
