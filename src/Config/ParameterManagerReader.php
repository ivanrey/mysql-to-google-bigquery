<?php

namespace MysqlToGoogleBigQuery\Config;

use Google\Cloud\ParameterManager\V1\Client\ParameterManagerClient;
use Google\Cloud\ParameterManager\V1\RenderParameterVersionRequest;

/**
 * Reads configuration from Google Cloud Parameter Manager.
 *
 *     pm://projects/my-project/locations/global/parameters/client-a/versions/latest
 *     pm://client-a                                      short form (global, latest)
 *
 * The version is *rendered*, so any __REF__(//secretmanager.googleapis.com/…)
 * inside it comes back already resolved: the parameter holds the plain
 * configuration and Secret Manager holds the sensitive values.
 *
 * Note: google/cloud-parametermanager is still pre-1.0, which is why the
 * dependency is confined to this class.
 */
class ParameterManagerReader implements ConfigReaderInterface
{
    public const SCHEME = 'pm';

    /** @var ParameterManagerClient[] Clients per API endpoint */
    private array $clients = [];

    /**
     * @param ParameterManagerClient|null $client Pre-built client, e.g. with custom options
     */
    public function __construct(private ?ParameterManagerClient $client = null)
    {
    }

    public function scheme(): string
    {
        return self::SCHEME;
    }

    public function read(string $uri): string
    {
        $name = self::resourceNameFor($uri);

        $response = $this->getClient(ReferenceParser::locationOf($name))
            ->renderParameterVersion(new RenderParameterVersionRequest(['name' => $name]));

        return $response->getRenderedPayload();
    }

    /**
     * Full Parameter Manager resource name behind a pm:// URI
     *
     * @param  string $uri pm:// reference, short or complete
     * @return string      projects/<p>/locations/<l>/parameters/<x>/versions/<v>
     */
    public static function resourceNameFor(string $uri): string
    {
        $reference = trim(ReferenceParser::stripScheme($uri, self::SCHEME), '/');

        if ($reference === '') {
            throw new \InvalidArgumentException('Empty Parameter Manager reference: expected pm://<parameter> or pm://projects/…');
        }

        if (!str_starts_with($reference, 'projects/')) {
            $reference = 'projects/' . ReferenceParser::defaultProject() . '/locations/global/parameters/' . $reference;
        }

        if (!str_contains($reference, '/versions/')) {
            $reference .= '/versions/latest';
        }

        return $reference;
    }

    /**
     * Regional parameters live behind a regional endpoint; global ones use the
     * default. Clients are reused across reads of the same endpoint.
     */
    private function getClient(?string $location): ParameterManagerClient
    {
        if ($this->client) {
            return $this->client;
        }

        $endpoint = $location === null
            ? ''
            : 'parametermanager.' . $location . '.rep.googleapis.com';

        return $this->clients[$endpoint] ??= new ParameterManagerClient(
            $endpoint === '' ? [] : ['apiEndpoint' => $endpoint]
        );
    }
}
