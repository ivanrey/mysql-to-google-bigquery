<?php
namespace MysqlToGoogleBigQuery\Tests\Config;

use MysqlToGoogleBigQuery\Config\RemoteConfigResolver;
use PHPUnit\Framework\TestCase;

class RemoteConfigResolverTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
    }

    public function testReadsThroughTheReaderOfTheScheme(): void
    {
        $resolver = new RemoteConfigResolver(
            new FakeConfigReader('sm', ['sm://db-pass' => 's3cret']),
            new FakeConfigReader('pm', ['pm://client-a' => 'BQ_DATASET=from_parameter'])
        );

        $this->assertSame('s3cret', $resolver->read('sm://db-pass'));
        $this->assertSame('BQ_DATASET=from_parameter', $resolver->read('pm://client-a'));
    }

    public function testEachReferenceIsReadOnlyOnce(): void
    {
        $reader = new FakeConfigReader('sm', ['sm://db-pass' => 's3cret']);
        $resolver = new RemoteConfigResolver($reader);

        $resolver->read('sm://db-pass');
        $resolver->read('sm://db-pass');

        $this->assertSame(1, $reader->reads);
    }

    public function testUnknownSchemeIsRejectedWithTheKnownOnes(): void
    {
        $resolver = new RemoteConfigResolver(new FakeConfigReader('sm'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sm://');

        $resolver->read('vault://db-pass');
    }

    public function testOnlyRegisteredSchemesCountAsReferences(): void
    {
        $resolver = new RemoteConfigResolver(new FakeConfigReader('sm'));

        $this->assertTrue($resolver->isReference('sm://db-pass'));
        $this->assertFalse($resolver->isReference('pm://client-a'));
        $this->assertFalse($resolver->isReference('/etc/keys/key.json'));
        $this->assertFalse($resolver->isReference('plain-password'));
        $this->assertFalse($resolver->isReference(null));
    }

    public function testEnvironmentReferencesAreReplacedByTheirValues(): void
    {
        $_ENV['DB_PASSWORD'] = 'sm://db-pass';
        $_ENV['DB_USERNAME'] = 'reporting';

        $resolver = new RemoteConfigResolver(new FakeConfigReader('sm', ['sm://db-pass' => 's3cret']));

        $this->assertSame(['DB_PASSWORD'], $resolver->resolveEnvironmentVariables());
        $this->assertSame('s3cret', $_ENV['DB_PASSWORD']);
        // Plain values are left untouched
        $this->assertSame('reporting', $_ENV['DB_USERNAME']);
    }

    public function testReferencesOnlyPresentInTheRealEnvironmentAreResolved(): void
    {
        // PHP CLI defaults to variables_order="GPCS": an exported variable
        // never reaches $_ENV on its own
        putenv('DB_PASSWORD=sm://db-pass');

        try {
            $resolver = new RemoteConfigResolver(new FakeConfigReader('sm', ['sm://db-pass' => 's3cret']));

            $this->assertSame(['DB_PASSWORD'], $resolver->resolveEnvironmentVariables());
            $this->assertSame('s3cret', $_ENV['DB_PASSWORD']);
        } finally {
            putenv('DB_PASSWORD');
        }
    }

    public function testSkippedVariablesAreLeftUntouched(): void
    {
        // CONFIG_SOURCE holds the reference the configuration came from:
        // resolving it would overwrite it with the whole payload
        $_ENV['CONFIG_SOURCE'] = 'sm://client-a-env';
        $_ENV['DB_PASSWORD'] = 'sm://db-pass';

        $resolver = new RemoteConfigResolver(new FakeConfigReader('sm', [
            'sm://client-a-env' => "DB_PASSWORD=sm://db-pass\n",
            'sm://db-pass' => 's3cret',
        ]));

        $this->assertSame(['DB_PASSWORD'], $resolver->resolveEnvironmentVariables(['CONFIG_SOURCE']));
        $this->assertSame('sm://client-a-env', $_ENV['CONFIG_SOURCE']);
    }

    public function testFailureNamesTheVariableAndTheReferenceButNotTheValue(): void
    {
        $_ENV['DB_PASSWORD'] = 'sm://missing';

        $resolver = new RemoteConfigResolver(new FakeConfigReader('sm'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve DB_PASSWORD');

        $resolver->resolveEnvironmentVariables();
    }
}
