<p align="center"><img src="https://cloud.githubusercontent.com/assets/2197005/19776979/f4abd1be-9c54-11e6-9842-212f26e765a5.png" alt="MySQL to Google BigQuery Logo" /></p>

<h1 align="center">MySQL to Google BigQuery Sync Tool</h1>

<p align="center">
  <a href="https://github.com/ivanrey/mysql-to-google-bigquery/actions/workflows/tests.yml"><img src="https://github.com/ivanrey/mysql-to-google-bigquery/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
</p>

## Table of Contents

+ [How it works](#how-it-works)
+ [Requirements](#requirements)
+ [Usage](#usage)
+ [Credits](#credits)
+ [License](#license)

## How it works

Steps when no order column has been supplied:

+ Count MySQL table rows
+ Count BigQuery table rows
+ MySQL rows > BigQuery rows?
+ Get the rows diff, split in batches of XXXXX rows/batch

Steps when order column has been supplied:

+ Get max value for order column from MySQL table
+ Get max value for order column from BigQuery table
+ Max value MySQL > Max value BigQuery?
+ Delete all rows with order column value = max value BigQuery 
to make sure no duplicate records are being created in BigQuery
+ Get max value for order column from BigQuery table
+ Get the rows diff based on new max value BigQuery, 
split in batches of XXXXX rows/batch

Final three steps:

+ Dump MySQL rows to a JSON
+ Send JSON to BigQuery
+ Repeat until all batches are sent

Tip: Create a cron job for keep syncing the tables using an interval like 15 minutes (respect the Load Jobs [quota policy](https://cloud.google.com/bigquery/quota-policy))

## Requirements

The following PHP versions are supported:

+ PHP 7
+ HHVM
+ PDO Extension with MySQL driver

## Usage

Download the library using [composer](https://packagist.org/packages/memeddev/mysql-to-google-bigquery):

```bash
$ composer require memeddev/mysql-to-google-bigquery
```

Now, define some environment variables or create a `.env` file on the root of the project, replacing the values:

```text
BQ_PROJECT_ID=bigquery-project-id
BQ_KEY_FILE=google-service-account-json-key-file.json
BQ_DATASET=bigquery-dataset-name

DB_DATABASE_NAME=mysql-database-name
DB_USERNAME=mysql_username
DB_PASSWORD=mysql_password
DB_HOST=mysql-host

IGNORE_COLUMNS=password,hidden_column,another_column
```

### Incremental sync and the `created_at` column

The incremental sync (`--order-column` / `ORDER_COLUMN`) filters its BigQuery
queries by a `created_at` column to limit the scanned data, so **every table
synced incrementally must have a `created_at` column** (the sync fails early
with a clear error if it is missing or listed in `IGNORE_COLUMNS`). Tables
without it can still be synced with `--un-buffer --delete-table` (full dump).

How far back those filters look is configurable (any `strtotime()`-parseable
expression):

```text
# Global lookback window, default: -3 month. Must look backwards
# (a future expression like "8 days" is rejected). A blank value falls
# back to the default.
CREATED_AT_LOOKBACK=-8 days

# Per-table override: CREATED_AT_LOOKBACK_<TABLE>, where <TABLE> is the
# *BigQuery* table name (the one the queries filter on, i.e. the
# --bigquery-table-name if you set it, otherwise the source table name),
# uppercased with non-alphanumeric characters replaced by "_"
CREATED_AT_LOOKBACK_USER_LOGS=-30 days
```

Keep the window wide enough to always reach the newest rows of the table. If
the BigQuery table has rows but **none** of them fall inside the window (a
table with no recent activity, or a cron stopped for longer than the window),
the sync cannot tell where to resume, so it **aborts** instead of assuming the
table is empty — which would re-insert the whole MySQL table on top of the
existing rows and duplicate them. Widen `CREATED_AT_LOOKBACK[_<TABLE>]`, or
reload the table with `--un-buffer --delete-table`.

### Several configurations (environments)

The same installation can serve several configurations — one per client,
database or stage. Put each one in its own directory, with its `.env` and its
service account key, under a config directory (`envs/` next to the project by
default):

```text
envs/
├── client-a/
│   ├── .env
│   └── service-account-key.json
└── client-b/
    ├── .env
    └── service-account-key.json
```

and select it by name, from any working directory:

```bash
bin/console sync log_entries -o id --env=client-a
```

- `--env=<name>` loads `<config dir>/<name>/.env`.
- `--env-file=/path/to/.env` loads a specific file instead (mutually exclusive
  with `--env`).
- `--config-dir=/path/to/configs` (or the `CONFIG_DIR` environment variable)
  points at the directory holding the environments. Relative values are
  resolved against the current directory.
- Without either flag, `<cwd>/.env` is loaded, as it always was.

Variables already present in the environment of the process win over the ones
in the loaded configuration, so a single value can be overridden for one run
(`BQ_DATASET=staging bin/console sync …`) without touching the file.

Relative `BQ_KEY_FILE` and `CACHE_DIR` values are resolved **against the
directory of the loaded `.env`**, not against the current directory, so each
environment can keep its key next to its configuration.

### Configuration from Google Cloud (Secret Manager / Parameter Manager)

Nothing sensitive has to live on disk. Authentication uses the **Application
Default Credentials** of the host (a GCE/GKE/Cloud Run service account), so the
identity that opens the secrets is the machine's, not a file.

**A single value from Secret Manager** — any variable whose value is a `sm://`
reference is resolved at startup, whatever its source:

```text
DB_PASSWORD=sm://projects/my-project/secrets/db-pass/versions/latest
DB_PASSWORD=sm://db-pass            # short form: BQ_PROJECT_ID, latest version
DB_PASSWORD=sm://db-pass/versions/3 # pinned version
```

References also work when they are **exported in the environment** instead of
written in a file:

```bash
DB_PASSWORD=sm://db-pass bin/console sync log_entries -o id --env=client-a
```

**The whole configuration from Google Cloud** — `--env-file` also takes a
reference instead of a path:

```bash
# the payload of the secret, parsed as a .env
bin/console sync log_entries -o id --env-file=sm://client-a-env

# a parameter version, rendered: its __REF__(//secretmanager.googleapis.com/…)
# come back already resolved, so the parameter holds the plain configuration
# and Secret Manager holds the sensitive values
bin/console sync log_entries -o id --env-file=pm://client-a
```

The payload must be `.env` content (`NAME=value` lines), so a Parameter Manager
parameter has to be created with the **`UNFORMATTED`** format — JSON and YAML
parameters are rejected with a clear error. Since a remote configuration has no
directory, `BQ_KEY_FILE` and `CACHE_DIR` must be **absolute paths** (or, for the
key, a `sm://` reference); a relative one fails instead of being resolved
against whatever directory the cron happened to run from.

**No key file at all** — leave `BQ_KEY_FILE` unset and BigQuery authenticates
with the same Application Default Credentials; or point it at a secret
(`BQ_KEY_FILE=sm://bq-service-account`) and the key is used from memory,
without ever being written to disk.

Roles needed: `roles/secretmanager.secretAccessor` on the secrets and
`roles/parametermanager.parameterAccessor` on the parameter. Values are read
on every run and never cached on disk. Regional secrets and parameters are
reached through their regional endpoint automatically.

PS: To create the `Google Service Account JSON Key File`, access [https://console.cloud.google.com/apis/credentials/serviceaccountkey](https://console.cloud.google.com/apis/credentials/serviceaccountkey)

Run:

```bash
vendor/bin/console sync table-name
```

If you want to auto create the table on BigQuery:

```bash
vendor/bin/console sync table-name --create-table
```

If you want to delete (and create) the table on BigQuery for a full dump:

```bash
vendor/bin/console sync table-name --delete-table
```

## Credits

:heart: Memed SA ([memed.com.br](https://memed.com.br))

## License

MIT license, see [LICENSE](LICENSE)
