<?php

namespace MysqlToGoogleBigQuery\Config;

use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;

/**
 * Reads values from Google Cloud Secret Manager.
 *
 *     sm://projects/my-project/secrets/db-pass/versions/latest   full name
 *     sm://db-pass                                               short form
 *     sm://db-pass/versions/3                                     pinned version
 *
 * The short form uses BQ_PROJECT_ID (or GOOGLE_CLOUD_PROJECT) and the latest
 * version. Authentication is the Application Default Credentials of the host,
 * so no key file has to sit on disk.
 */
class SecretManagerReader implements ConfigReaderInterface
{
    public const SCHEME = 'sm';

    /** @var SecretManagerServiceClient[] Clients per API endpoint */
    private array $clients = [];

    /**
     * @param SecretManagerServiceClient|null $client Pre-built client, e.g. with custom options
     */
    public function __construct(private ?SecretManagerServiceClient $client = null)
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
            ->accessSecretVersion(AccessSecretVersionRequest::build($name));

        return $response->getPayload()->getData();
    }

    /**
     * Full Secret Manager resource name behind a sm:// URI
     *
     * @param  string $uri sm:// reference, short or complete
     * @return string      projects/<project>/secrets/<secret>/versions/<version>
     */
    public static function resourceNameFor(string $uri): string
    {
        $reference = trim(ReferenceParser::stripScheme($uri, self::SCHEME), '/');

        if ($reference === '') {
            throw new \InvalidArgumentException('Empty Secret Manager reference: expected sm://<secret> or sm://projects/…');
        }

        if (!str_starts_with($reference, 'projects/')) {
            $reference = 'projects/' . ReferenceParser::defaultProject() . '/secrets/' . $reference;
        }

        if (!str_contains($reference, '/versions/')) {
            $reference .= '/versions/latest';
        }

        return $reference;
    }

    /**
     * Regional secrets live behind a regional endpoint; global ones use the
     * default. Clients are reused across reads of the same endpoint.
     */
    private function getClient(?string $location): SecretManagerServiceClient
    {
        if ($this->client) {
            return $this->client;
        }

        $endpoint = $location === null
            ? ''
            : 'secretmanager.' . $location . '.rep.googleapis.com';

        return $this->clients[$endpoint] ??= new SecretManagerServiceClient(
            $endpoint === '' ? [] : ['apiEndpoint' => $endpoint]
        );
    }
}
