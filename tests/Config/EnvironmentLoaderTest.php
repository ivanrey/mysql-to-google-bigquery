<?php
namespace MysqlToGoogleBigQuery\Tests\Config;

use MysqlToGoogleBigQuery\Config\EnvironmentLoader;
use MysqlToGoogleBigQuery\Config\RemoteConfigResolver;
use PHPUnit\Framework\TestCase;

class EnvironmentLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $projectRoot;
    private string $originalCwd;
    private array $originalEnv;
    private array $originalServer;

    protected function setUp(): void
    {
        // phpdotenv is immutable: variables already present are never
        // overwritten, so each test needs a clean $_ENV/$_SERVER
        $this->originalEnv = $_ENV;
        $this->originalServer = $_SERVER;
        $this->originalCwd = getcwd();

        $this->tmpDir = sys_get_temp_dir() . '/env-loader-' . uniqid();
        $this->projectRoot = $this->tmpDir . '/project';
        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeDirectory($this->tmpDir);
        putenv('CONFIG_DIR');

        $_ENV = $this->originalEnv;
        $_SERVER = $this->originalServer;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Write a .env file, creating its directory, and return its path
     */
    private function writeEnvFile(string $path, array $variables): string
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $lines = [];
        foreach ($variables as $name => $value) {
            $lines[] = $name . '=' . $value;
        }

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);

        return $path;
    }

    private function loader(?RemoteConfigResolver $resolver = null): EnvironmentLoader
    {
        // Without an explicit resolver the loader builds the real Google Cloud
        // readers; they are lazy, so no reference means no API call
        return new EnvironmentLoader($this->projectRoot, $resolver);
    }

    private function resolverWith(array $values): RemoteConfigResolver
    {
        return new RemoteConfigResolver(
            new FakeConfigReader('sm', $values),
            new FakeConfigReader('pm', $values)
        );
    }

    public function testEnvNameLoadsTheEnvironmentFromTheDefaultConfigDir(): void
    {
        $path = $this->writeEnvFile(
            $this->projectRoot . '/envs/client-a/.env',
            ['BQ_DATASET' => 'dataset_a']
        );

        $loaded = $this->loader()->load(env: 'client-a');

        $this->assertSame($path, $loaded);
        $this->assertSame('dataset_a', $_ENV['BQ_DATASET']);
    }

    public function testEnvNameWorksFromAnyWorkingDirectory(): void
    {
        $this->writeEnvFile($this->projectRoot . '/envs/client-a/.env', ['BQ_DATASET' => 'dataset_a']);

        // The point of the flag: no cd into the environment directory
        $elsewhere = $this->tmpDir . '/elsewhere';
        mkdir($elsewhere, 0777, true);
        chdir($elsewhere);

        $this->loader()->load(env: 'client-a');

        $this->assertSame('dataset_a', $_ENV['BQ_DATASET']);
    }

    public function testConfigDirOptionOverridesTheDefault(): void
    {
        $path = $this->writeEnvFile($this->tmpDir . '/configs/client-b/.env', ['BQ_DATASET' => 'dataset_b']);

        $loaded = $this->loader()->load(env: 'client-b', configDir: $this->tmpDir . '/configs');

        $this->assertSame($path, $loaded);
        $this->assertSame('dataset_b', $_ENV['BQ_DATASET']);
    }

    public function testConfigDirEnvironmentVariableIsUsedWhenTheOptionIsMissing(): void
    {
        $this->writeEnvFile($this->tmpDir . '/configs/client-b/.env', ['BQ_DATASET' => 'dataset_b']);
        putenv('CONFIG_DIR=' . $this->tmpDir . '/configs');

        $this->loader()->load(env: 'client-b');

        $this->assertSame('dataset_b', $_ENV['BQ_DATASET']);
    }

    public function testConfigDirOptionWinsOverTheEnvironmentVariable(): void
    {
        $this->writeEnvFile($this->tmpDir . '/configs/client-b/.env', ['BQ_DATASET' => 'from_option']);
        putenv('CONFIG_DIR=' . $this->tmpDir . '/ignored');

        $this->loader()->load(env: 'client-b', configDir: $this->tmpDir . '/configs');

        $this->assertSame('from_option', $_ENV['BQ_DATASET']);
    }

    public function testRelativeConfigDirIsResolvedAgainstTheWorkingDirectory(): void
    {
        $this->writeEnvFile($this->tmpDir . '/configs/client-b/.env', ['BQ_DATASET' => 'dataset_b']);
        chdir($this->tmpDir);

        $this->loader()->load(env: 'client-b', configDir: 'configs');

        $this->assertSame('dataset_b', $_ENV['BQ_DATASET']);
    }

    public function testEnvFileLoadsAnArbitraryPath(): void
    {
        $path = $this->writeEnvFile($this->tmpDir . '/somewhere/custom.env', ['BQ_DATASET' => 'custom']);

        $loaded = $this->loader()->load(envFile: $path);

        $this->assertSame($path, $loaded);
        $this->assertSame('custom', $_ENV['BQ_DATASET']);
    }

    public function testWithoutOptionsItFallsBackToTheWorkingDirectory(): void
    {
        $this->writeEnvFile($this->tmpDir . '/legacy/.env', ['BQ_DATASET' => 'legacy']);
        chdir($this->tmpDir . '/legacy');

        // Backwards compatibility: the pre-flag behaviour (cd + run).
        // getcwd() resolves symlinks (/var -> /private/var on macOS), so it is
        // the reference for the expected path
        $this->assertSame(getcwd() . '/.env', $this->loader()->load());
        $this->assertSame('legacy', $_ENV['BQ_DATASET']);
    }

    public function testWithoutAnyEnvFileItLoadsNothingAndDoesNotFail(): void
    {
        $empty = $this->tmpDir . '/empty';
        mkdir($empty, 0777, true);
        chdir($empty);

        // The configuration may come from the real environment
        $this->assertNull($this->loader()->load());
        $this->assertArrayNotHasKey(EnvironmentLoader::ENV_DIR, $_ENV);
    }

    public function testLoadedEnvDirectoryIsExposedForPathResolution(): void
    {
        $this->writeEnvFile($this->projectRoot . '/envs/client-a/.env', ['BQ_DATASET' => 'dataset_a']);

        $this->loader()->load(env: 'client-a');

        $this->assertSame($this->projectRoot . '/envs/client-a', $_ENV[EnvironmentLoader::ENV_DIR]);
    }

    public function testDerivedEnvDirWinsOverAValueWrittenInTheFile(): void
    {
        $this->writeEnvFile(
            $this->projectRoot . '/envs/client-a/.env',
            [EnvironmentLoader::ENV_DIR => '/somewhere/else']
        );

        $this->loader()->load(env: 'client-a');

        $this->assertSame($this->projectRoot . '/envs/client-a', $_ENV[EnvironmentLoader::ENV_DIR]);
    }

    public function testUnknownEnvironmentFailsWithTheSearchedPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($this->projectRoot . '/envs/missing/.env');

        $this->loader()->load(env: 'missing');
    }

    public function testMissingEnvFileFailsWithAClearError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Env file not found');

        $this->loader()->load(envFile: $this->tmpDir . '/nope.env');
    }

    public function testEnvAndEnvFileTogetherAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        $this->loader()->load(env: 'client-a', envFile: '/tmp/whatever.env');
    }

    /**
     * @dataProvider invalidEnvNames
     */
    public function testEnvNameWithPathSeparatorsIsRejected(string $env): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid environment name');

        $this->loader()->load(env: $env);
    }

    public static function invalidEnvNames(): array
    {
        return [
            'traversal' => ['../../etc'],
            'nested path' => ['client-a/sub'],
            'backslash' => ['client-a\\sub'],
            'parent' => ['..'],
            'blank' => ['  '],
        ];
    }

    public function testConfigurationCanComeFromASecret(): void
    {
        $resolver = $this->resolverWith([
            'sm://client-a-env' => "BQ_DATASET=from_secret\nDB_USERNAME=reporting\n",
        ]);

        $loaded = $this->loader($resolver)->load(envFile: 'sm://client-a-env');

        // Nothing touched this filesystem: the URI itself is the source
        $this->assertSame('sm://client-a-env', $loaded);
        $this->assertSame('from_secret', $_ENV['BQ_DATASET']);
        $this->assertSame('reporting', $_ENV['DB_USERNAME']);
    }

    public function testConfigurationCanComeFromARenderedParameter(): void
    {
        $resolver = $this->resolverWith([
            'pm://client-a' => "BQ_DATASET=from_parameter\nDB_PASSWORD=hydrated-by-render\n",
        ]);

        $this->loader($resolver)->load(envFile: 'pm://client-a');

        $this->assertSame('from_parameter', $_ENV['BQ_DATASET']);
        // Parameter Manager resolves its __REF__ to Secret Manager on render
        $this->assertSame('hydrated-by-render', $_ENV['DB_PASSWORD']);
    }

    public function testSecretReferencesInsideTheEnvFileAreResolved(): void
    {
        $this->writeEnvFile($this->projectRoot . '/envs/client-a/.env', [
            'DB_USERNAME' => 'reporting',
            'DB_PASSWORD' => 'sm://db-pass',
        ]);

        $this->loader($this->resolverWith(['sm://db-pass' => 's3cret']))->load(env: 'client-a');

        $this->assertSame('s3cret', $_ENV['DB_PASSWORD']);
        $this->assertSame('reporting', $_ENV['DB_USERNAME']);
    }

    public function testSecretReferencesAreResolvedWithoutAnyEnvFile(): void
    {
        $empty = $this->tmpDir . '/empty';
        mkdir($empty, 0777, true);
        chdir($empty);

        // The reference can come from the real environment of the process
        $_ENV['DB_PASSWORD'] = 'sm://db-pass';

        $this->loader($this->resolverWith(['sm://db-pass' => 's3cret']))->load();

        $this->assertSame('s3cret', $_ENV['DB_PASSWORD']);
    }

    public function testEnvironmentVariablesStillWinOverARemoteConfiguration(): void
    {
        $_ENV['BQ_DATASET'] = 'from_environment';

        $resolver = $this->resolverWith(['sm://client-a-env' => "BQ_DATASET=from_secret\n"]);
        $this->loader($resolver)->load(envFile: 'sm://client-a-env');

        // Same immutability as a .env file
        $this->assertSame('from_environment', $_ENV['BQ_DATASET']);
    }

    public function testResolvePathUsesTheEnvDirectoryForRelativePaths(): void
    {
        $_ENV[EnvironmentLoader::ENV_DIR] = '/configs/client-a';

        $this->assertSame(
            '/configs/client-a/service-account-key.json',
            EnvironmentLoader::resolvePath('service-account-key.json')
        );
    }

    public function testResolvePathLeavesAbsolutePathsUntouched(): void
    {
        $_ENV[EnvironmentLoader::ENV_DIR] = '/configs/client-a';

        $this->assertSame('/etc/keys/key.json', EnvironmentLoader::resolvePath('/etc/keys/key.json'));
    }

    public function testResolvePathFallsBackToTheWorkingDirectory(): void
    {
        unset($_ENV[EnvironmentLoader::ENV_DIR]);
        chdir($this->tmpDir);

        // Same behaviour as before the flag existed
        $this->assertSame(getcwd() . '/key.json', EnvironmentLoader::resolvePath('key.json'));
    }
}
