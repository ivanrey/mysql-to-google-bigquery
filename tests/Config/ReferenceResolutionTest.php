<?php
namespace MysqlToGoogleBigQuery\Tests\Config;

use MysqlToGoogleBigQuery\Config\ParameterManagerReader;
use MysqlToGoogleBigQuery\Config\ReferenceParser;
use MysqlToGoogleBigQuery\Config\SecretManagerReader;
use PHPUnit\Framework\TestCase;

/**
 * Resolution of sm:// and pm:// references into Google Cloud resource names.
 * The API calls themselves are not covered: both generated clients are final,
 * so they cannot be doubled; everything around them is pure and tested here.
 */
class ReferenceResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['BQ_PROJECT_ID'] = 'my-project';
    }

    protected function tearDown(): void
    {
        unset($_ENV['BQ_PROJECT_ID'], $_ENV['GOOGLE_CLOUD_PROJECT']);
    }

    public function testShortSecretReferenceUsesTheProjectAndTheLatestVersion(): void
    {
        $this->assertSame(
            'projects/my-project/secrets/db-pass/versions/latest',
            SecretManagerReader::resourceNameFor('sm://db-pass')
        );
    }

    public function testShortSecretReferenceCanPinAVersion(): void
    {
        $this->assertSame(
            'projects/my-project/secrets/db-pass/versions/3',
            SecretManagerReader::resourceNameFor('sm://db-pass/versions/3')
        );
    }

    public function testFullSecretReferenceIsKept(): void
    {
        $name = 'projects/other/secrets/db-pass/versions/7';

        $this->assertSame($name, SecretManagerReader::resourceNameFor('sm://' . $name));
    }

    public function testFullSecretReferenceWithoutVersionDefaultsToLatest(): void
    {
        $this->assertSame(
            'projects/other/secrets/db-pass/versions/latest',
            SecretManagerReader::resourceNameFor('sm://projects/other/secrets/db-pass')
        );
    }

    public function testSecretProjectFallsBackToGoogleCloudProject(): void
    {
        unset($_ENV['BQ_PROJECT_ID']);
        $_ENV['GOOGLE_CLOUD_PROJECT'] = 'adc-project';

        $this->assertSame(
            'projects/adc-project/secrets/db-pass/versions/latest',
            SecretManagerReader::resourceNameFor('sm://db-pass')
        );
    }

    public function testShortReferenceWithoutAnyProjectFailsWithAClearError(): void
    {
        unset($_ENV['BQ_PROJECT_ID']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('short reference needs a project');

        SecretManagerReader::resourceNameFor('sm://db-pass');
    }

    public function testEmptySecretReferenceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty Secret Manager reference');

        SecretManagerReader::resourceNameFor('sm://');
    }

    public function testShortParameterReferenceIsGlobalAndLatest(): void
    {
        $this->assertSame(
            'projects/my-project/locations/global/parameters/client-a/versions/latest',
            ParameterManagerReader::resourceNameFor('pm://client-a')
        );
    }

    public function testFullParameterReferenceIsKept(): void
    {
        $name = 'projects/other/locations/us-central1/parameters/client-a/versions/2';

        $this->assertSame($name, ParameterManagerReader::resourceNameFor('pm://' . $name));
    }

    public function testWrongSchemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a sm:// reference');

        SecretManagerReader::resourceNameFor('pm://client-a');
    }

    public function testSchemeDetection(): void
    {
        $this->assertSame('sm', ReferenceParser::schemeOf('sm://db-pass'));
        $this->assertSame('pm', ReferenceParser::schemeOf('pm://client-a'));
        $this->assertNull(ReferenceParser::schemeOf('/etc/keys/key.json'));
        $this->assertNull(ReferenceParser::schemeOf('plain-value'));
        // A password that merely contains "://" is not a reference
        $this->assertNull(ReferenceParser::schemeOf('p@ss://word'));
    }

    public function testRegionalResourcesAreDetectedForTheirEndpoint(): void
    {
        $this->assertSame(
            'us-central1',
            ReferenceParser::locationOf('projects/p/locations/us-central1/parameters/x/versions/1')
        );

        // "global" is served by the default endpoint, like a name without location
        $this->assertNull(ReferenceParser::locationOf('projects/p/locations/global/parameters/x/versions/1'));
        $this->assertNull(ReferenceParser::locationOf('projects/p/secrets/s/versions/1'));
    }
}
