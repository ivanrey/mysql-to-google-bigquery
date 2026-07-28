<?php

namespace MysqlToGoogleBigQuery\Config;

/**
 * Small helpers shared by the readers of remote configuration.
 */
class ReferenceParser
{
    /**
     * Scheme of a URI ("sm://x" -> "sm"), null when it has none
     */
    public static function schemeOf(string $value): ?string
    {
        return preg_match('#^([a-z][a-z0-9+.-]*)://#i', $value, $matches) === 1
            ? strtolower($matches[1])
            : null;
    }

    /**
     * @param  string $uri    Full URI
     * @param  string $scheme Expected scheme, without "://"
     * @return string         The URI without its scheme
     */
    public static function stripScheme(string $uri, string $scheme): string
    {
        $prefix = $scheme . '://';

        if (stripos($uri, $prefix) !== 0) {
            throw new \InvalidArgumentException('Expected a ' . $prefix . ' reference, got: ' . $uri);
        }

        return substr($uri, strlen($prefix));
    }

    /**
     * Location of a Google Cloud resource name, null when it is global (a
     * regional resource is only reachable through its regional endpoint)
     */
    public static function locationOf(string $resourceName): ?string
    {
        if (preg_match('#/locations/([^/]+)#', $resourceName, $matches) !== 1) {
            return null;
        }

        return $matches[1] === 'global' ? null : $matches[1];
    }

    /**
     * Project used to complete short references
     */
    public static function defaultProject(): string
    {
        foreach (['BQ_PROJECT_ID', 'GOOGLE_CLOUD_PROJECT'] as $variable) {
            $value = $_ENV[$variable] ?? getenv($variable);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        throw new \InvalidArgumentException(
            'A short reference needs a project: set BQ_PROJECT_ID (or GOOGLE_CLOUD_PROJECT), ' .
            'or use the full resource name (projects/<project>/…).'
        );
    }
}
