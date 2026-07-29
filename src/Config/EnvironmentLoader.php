<?php

namespace MysqlToGoogleBigQuery\Config;

use Dotenv\Dotenv;
use Dotenv\Repository\RepositoryBuilder;

/**
 * Resolves and loads the .env of the environment to run against.
 *
 * The same code base is typically used with several configurations (one per
 * client, per database, per stage...). Selecting one used to mean cd-ing into
 * its directory, because only "<cwd>/.env" was ever loaded; this loader picks
 * the configuration explicitly, so the command works from any directory.
 */
class EnvironmentLoader
{
    /**
     * Derived variable holding the directory of the loaded .env. Not meant to
     * be set by hand: relative paths in the configuration (BQ_KEY_FILE,
     * CACHE_DIR) are resolved against it instead of against the cwd.
     */
    public const ENV_DIR = 'ENV_DIR';

    /**
     * Derived variable holding the URI the configuration came from, when it
     * was not a file. Relative paths have no base directory in that case, so
     * resolvePath() rejects them instead of silently falling back to the cwd.
     */
    public const CONFIG_SOURCE = 'CONFIG_SOURCE';

    /**
     * Directory holding one subdirectory per environment. Overridable with
     * --config-dir or the CONFIG_DIR environment variable.
     */
    public const DEFAULT_CONFIG_DIR = 'envs';

    /**
     * @param string                    $projectRoot Root of the project, used to resolve
     *                                               the default config directory
     * @param RemoteConfigResolver|null $resolver    Reader of sm:// and pm:// references
     *                                               (injected by tests)
     */
    public function __construct(private string $projectRoot, private ?RemoteConfigResolver $resolver = null)
    {
    }

    private function resolver(): RemoteConfigResolver
    {
        return $this->resolver ??= RemoteConfigResolver::withDefaultReaders();
    }

    /**
     * Load the configuration of the selected environment.
     *
     * Precedence: $envFile (explicit path or URI) > $env (<config dir>/<env>/.env)
     * > <cwd>/.env (the historical behaviour, kept for backwards compatibility).
     *
     * Whatever the source, any sm:// reference among the resulting variables is
     * replaced by its value afterwards.
     *
     * @param  string|null $env        Environment name (directory under the config dir)
     * @param  string|null $envFile    Path or URI (sm://, pm://) of a specific configuration
     * @param  string|null $configDir  Directory holding the environments
     * @return string|null             Path/URI of the loaded configuration, null if there was none
     */
    public function load(?string $env = null, ?string $envFile = null, ?string $configDir = null): ?string
    {
        $this->importRealEnvironment();

        $source = $this->loadSource($env, $envFile, $configDir);

        // Runs even without a .env: the references can come from the real
        // environment of the process too
        $this->resolver()->resolveEnvironmentVariables([self::ENV_DIR, self::CONFIG_SOURCE]);

        return $source;
    }

    /**
     * Copy the real environment of the process into $_ENV.
     *
     * PHP CLI ships with variables_order="GPCS", which leaves $_ENV empty —
     * and $_ENV is where this project reads its configuration from. Without
     * this, an exported DB_PASSWORD=sm://… would never be seen (let alone
     * resolved), and the immutability of the loaded .env would be decided
     * against an empty set instead of against the actual environment.
     */
    private function importRealEnvironment(): void
    {
        $environment = getenv();

        if (!is_array($environment)) {
            return;
        }

        foreach ($environment as $name => $value) {
            // Already-present values win: this only fills the gaps left by
            // variables_order, it never overrides what is in $_ENV
            $_ENV[$name] ??= $value;
        }
    }

    /**
     * Load the variables of the selected source, without resolving references
     *
     * @return string|null Path/URI of the loaded configuration, null if there was none
     */
    private function loadSource(?string $env, ?string $envFile, ?string $configDir): ?string
    {
        if ($env !== null && $envFile !== null) {
            throw new \InvalidArgumentException(
                '--env and --env-file are mutually exclusive: --env picks an environment ' .
                'by name inside the config directory, --env-file points at a file directly.'
            );
        }

        if ($envFile !== null) {
            // A URI (sm://, pm://) instead of a path: the whole configuration
            // comes from Google Cloud, so nothing has to sit on this disk
            if ($this->resolver()->isReference($envFile)) {
                $this->loadContents($this->resolver()->read($envFile), $envFile);

                // No directory to resolve relative paths against
                $_ENV[self::CONFIG_SOURCE] = $envFile;

                return $envFile;
            }

            $path = $this->toAbsolutePath($envFile);

            if (!is_file($path)) {
                throw new \InvalidArgumentException('Env file not found: ' . $path);
            }

            return $this->loadFile($path);
        }

        if ($env !== null) {
            $path = $this->resolveConfigDir($configDir) . '/' . $this->validateEnvName($env) . '/.env';

            if (!is_file($path)) {
                throw new \InvalidArgumentException(
                    'Environment "' . $env . '" not found: no .env at ' . $path . '. ' .
                    'Use --config-dir (or CONFIG_DIR) to point at the directory holding the environments.'
                );
            }

            return $this->loadFile($path);
        }

        // No environment selected: keep loading <cwd>/.env as before. Missing
        // is not an error, the configuration can come from the real environment
        $path = getcwd() . '/.env';

        return is_file($path) ? $this->loadFile($path) : null;
    }

    /**
     * Turn a configured relative path into an absolute one, based on the
     * directory of the loaded .env (falling back to the cwd, as before).
     *
     * @param  string $path Absolute or relative path
     * @return string       Absolute path
     */
    public static function resolvePath(string $path): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        if (isset($_ENV[self::ENV_DIR]) && $_ENV[self::ENV_DIR] !== '') {
            return rtrim($_ENV[self::ENV_DIR], '/') . '/' . $path;
        }

        // Configuration loaded from a URI: there is no directory to be
        // relative to, and falling back to the cwd would bring back exactly
        // the dependency this loader removes
        if (isset($_ENV[self::CONFIG_SOURCE]) && $_ENV[self::CONFIG_SOURCE] !== '') {
            throw new \RuntimeException(
                'Cannot resolve the relative path "' . $path . '": the configuration comes from "' .
                $_ENV[self::CONFIG_SOURCE] . '", which has no directory. Use an absolute path, ' .
                'or keep the value in Secret Manager (sm://…).'
            );
        }

        return rtrim(getcwd(), '/') . '/' . $path;
    }

    /**
     * An environment is a directory name inside the config directory; paths
     * belong to --env-file, which is explicit about what it loads.
     */
    private function validateEnvName(string $env): string
    {
        if (trim($env) === '' || preg_match('#[/\\\\]#', $env) || $env === '.' || $env === '..') {
            throw new \InvalidArgumentException(
                'Invalid environment name "' . $env . '": it must be a directory name inside ' .
                'the config directory, without path separators. Use --env-file for an arbitrary path.'
            );
        }

        return $env;
    }

    /**
     * --config-dir > CONFIG_DIR > <project root>/envs. Relative values are
     * resolved against the cwd, the way a shell argument reads.
     */
    private function resolveConfigDir(?string $configDir): string
    {
        $configDir ??= getenv('CONFIG_DIR') ?: ($_ENV['CONFIG_DIR'] ?? null);

        if ($configDir === null || trim($configDir) === '') {
            return $this->projectRoot . '/' . self::DEFAULT_CONFIG_DIR;
        }

        return rtrim($this->toAbsolutePath(trim($configDir)), '/');
    }

    private function toAbsolutePath(string $path): string
    {
        return self::isAbsolutePath($path) ? $path : getcwd() . '/' . $path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        // Windows drive letters and UNC paths are covered on purpose: the CLI
        // is not Unix-only and a "C:\..." path must not get the cwd prepended
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * Load .env contents that never touched this filesystem (a secret payload,
     * a rendered parameter), with the same immutability as a file: whatever is
     * already defined in the environment wins.
     *
     * @param string $contents .env formatted contents
     * @param string $source   URI the contents came from, for error messages
     */
    private function loadContents(string $contents, string $source): void
    {
        $trimmed = ltrim($contents);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            throw new \RuntimeException(
                'The configuration read from "' . $source . '" looks like JSON, but it has to be ' .
                '.env content (NAME=value lines). A Parameter Manager parameter must be created ' .
                'with the UNFORMATTED format for this; JSON and YAML parameters are not supported.'
            );
        }

        try {
            $variables = Dotenv::parse($contents);
        } catch (\Throwable $e) {
            // The parser quotes the offending line, and here that line is the
            // payload of a secret: report the source, never the message (not
            // even as a previous exception, which -v would print)
            throw new \RuntimeException(
                'The configuration read from "' . $source . '" is not valid .env content. ' .
                'The parser error is omitted on purpose: it quotes the offending line, ' .
                'which may hold a secret. Check the payload for unquoted values with spaces.'
            );
        }

        $repository = RepositoryBuilder::createWithDefaultAdapters()->immutable()->make();

        foreach ($variables as $name => $value) {
            if ($value !== null) {
                $repository->set($name, $value);
            }
        }
    }

    /**
     * @param  string $path Absolute path of an existing .env file
     * @return string       The same path, once loaded
     */
    private function loadFile(string $path): string
    {
        Dotenv::createImmutable(dirname($path), basename($path))->load();

        // Set after loading so the derived value always wins over a stale
        // ENV_DIR sitting in the file itself
        $_ENV[self::ENV_DIR] = dirname($path);

        return $path;
    }
}
