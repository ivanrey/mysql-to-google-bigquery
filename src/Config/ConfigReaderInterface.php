<?php

namespace MysqlToGoogleBigQuery\Config;

/**
 * Reads a piece of configuration living outside the filesystem, addressed by
 * a URI (for instance "sm://my-secret").
 */
interface ConfigReaderInterface
{
    /**
     * URI scheme this reader handles, without "://" (e.g. "sm")
     */
    public function scheme(): string;

    /**
     * @param  string $uri Full URI, including the scheme
     * @return string      The value behind the URI
     */
    public function read(string $uri): string;
}
