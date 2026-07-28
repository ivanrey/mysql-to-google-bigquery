<?php

namespace MysqlToGoogleBigQuery\Config;

use Dotenv\Dotenv;

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
     * Directory holding one subdirectory per environment. Overridable with
     * --config-dir or the CONFIG_DIR environment variable.
     */
    public const DEFAULT_CONFIG_DIR = 'envs';

    /**
     * @param string $projectRoot Root of the project, used to resolve the
     *                            default config directory
     */
    public function __construct(private string $projectRoot)
    {
    }

    /**
     * Load the .env of the selected environment.
     *
     * Precedence: $envFile (explicit path) > $env (<config dir>/<env>/.env) >
     * <cwd>/.env (the historical behaviour, kept for backwards compatibility).
     *
     * @param  string|null $env        Environment name (directory under the config dir)
     * @param  string|null $envFile    Path to a specific .env file
     * @param  string|null $configDir  Directory holding the environments
     * @return string|null             Path of the loaded .env, null if there was none
     */
    public function load(?string $env = null, ?string $envFile = null, ?string $configDir = null): ?string
    {
        if ($env !== null && $envFile !== null) {
            throw new \InvalidArgumentException(
                '--env and --env-file are mutually exclusive: --env picks an environment ' .
                'by name inside the config directory, --env-file points at a file directly.'
            );
        }

        if ($envFile !== null) {
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

        $base = (isset($_ENV[self::ENV_DIR]) && $_ENV[self::ENV_DIR] !== '')
            ? $_ENV[self::ENV_DIR]
            : getcwd();

        return rtrim($base, '/') . '/' . $path;
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
