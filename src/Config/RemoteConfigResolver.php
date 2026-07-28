<?php

namespace MysqlToGoogleBigQuery\Config;

/**
 * Routes configuration URIs to the reader of their scheme, so a value can live
 * in Google Cloud instead of on disk.
 *
 * Values are read once per URI and kept in memory only: writing a resolved
 * secret to disk would defeat the point of keeping it in Secret Manager.
 */
class RemoteConfigResolver
{
    /** @var ConfigReaderInterface[] Readers keyed by scheme */
    private array $readers = [];

    /** @var string[] Values already read, keyed by URI */
    private array $cache = [];

    public function __construct(ConfigReaderInterface ...$readers)
    {
        foreach ($readers as $reader) {
            $this->readers[$reader->scheme()] = $reader;
        }
    }

    /**
     * Resolver backed by the real Google Cloud clients. They authenticate with
     * the Application Default Credentials of the host and are only built when
     * a reference is actually read.
     */
    public static function withDefaultReaders(): self
    {
        return new self(new SecretManagerReader(), new ParameterManagerReader());
    }

    /**
     * True when the value is a URI this resolver knows how to read. Unknown
     * schemes (a DSN, an https:// URL) are left alone.
     */
    public function isReference(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $scheme = ReferenceParser::schemeOf($value);

        return $scheme !== null && isset($this->readers[$scheme]);
    }

    /**
     * @param  string $uri Reference to read
     * @return string      The value behind it
     */
    public function read(string $uri): string
    {
        if (array_key_exists($uri, $this->cache)) {
            return $this->cache[$uri];
        }

        $scheme = ReferenceParser::schemeOf($uri);

        if ($scheme === null || !isset($this->readers[$scheme])) {
            throw new \InvalidArgumentException(
                'Unsupported configuration reference "' . $uri . '": known schemes are ' .
                implode(', ', array_map(fn (string $s) => $s . '://', array_keys($this->readers))) . '.'
            );
        }

        try {
            return $this->cache[$uri] = $this->readers[$scheme]->read($uri);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // The URI is safe to show, the value never is
            throw new \RuntimeException(
                'Could not read the configuration reference "' . $uri . '": ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Replace every reference sitting in $_ENV by its value.
     *
     * Runs after the .env is loaded, so it covers variables coming from the
     * file, from a rendered parameter or from the real environment alike.
     *
     * @return string[] Names of the variables that were resolved (never their values)
     */
    public function resolveEnvironmentVariables(): array
    {
        $resolved = [];

        foreach ($_ENV as $name => $value) {
            if (!$this->isReference($value)) {
                continue;
            }

            try {
                $_ENV[$name] = $this->read($value);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Could not resolve ' . $name . ': ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $resolved[] = $name;
        }

        return $resolved;
    }
}
