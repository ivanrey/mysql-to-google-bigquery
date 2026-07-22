# AGENTS.md

Guía para agentes de IA (y humanos) que trabajen en este repositorio.

## Qué es este proyecto

Herramienta CLI en PHP que **sincroniza tablas de MySQL hacia Google BigQuery**.
Lee filas de MySQL, las convierte a JSON newline-delimited y las carga a BigQuery
mediante load jobs. Soporta sincronización incremental por columna de orden
(`--order-column`) o por diferencia de conteo de filas.

Fork mantenido en `github.com/ivanrey/mysql-to-google-bigquery` (basado en
`MemedDev/mysql-to-google-bigquery`).

## Principio de diseño: el proyecto es AGNÓSTICO

Es una herramienta **genérica** reutilizable por cualquiera. **No** acoples nada al
negocio ni a clientes concretos:

- Las variables de entorno, opciones CLI, rutas, defaults y nombres en el código
  **no** deben mencionar "Hércules" ni clientes específicos.
- Usa nombres neutros (p.ej. `CONFIG_DIR`, nunca `HERCULES_CONFIG_DIR`).
- Los detalles de despliegue multi-cliente se gestionan **fuera** de este repo.

## Estructura

```
bin/console                       Punto de entrada CLI (Symfony Console)
src/Console/Commands/SyncCommand  Comando `sync`
src/Services/SyncService          Lógica de sincronización (batches, unbuffered, waitJob)
src/Database/Mysql                Conexión MySQL (Doctrine DBAL) y tipos custom
src/Database/BigQuery             Cliente BigQuery, queries, load jobs
src/Doctrine/                     Tipos Doctrine custom (date / datetime para BigQuery)
```

## Configuración

Vía `.env` (cargado con `vlucas/phpdotenv`). Variables principales:

```
BQ_PROJECT_ID       ID del proyecto en GCP
BQ_KEY_FILE         Ruta al JSON de service account
BQ_DATASET          Dataset destino en BigQuery
BQ_LOCATION         Región del dataset (ej. US, southamerica-east1)
DB_DATABASE_NAME, DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT
IGNORE_COLUMNS      Columnas a omitir (separadas por coma)
CREATED_AT_LOOKBACK Ventana para filtros created_at (ej. "-8 days")
MAX_ROWS_PER_BATCH  Filas por batch (default 600000)
CACHE_DIR           Directorio para los JSON temporales
```

## Entorno de desarrollo

- **PHP 8.1**, stack de dependencias moderno (symfony 6.4, doctrine/dbal 3.8,
  google/cloud-bigquery 1.x):
  ```bash
  composer install
  ```
- El `location` (BQ_LOCATION) lo maneja nativamente `google/cloud-bigquery`:
  se pasa al `BigQueryClient` y viaja en la identidad de cada job, así que el
  polling funciona contra datasets regionales (no US/EU) sin workarounds.
  (Históricamente esto requería un parche al vendor — eliminado en la issue #4.)

## Uso

```bash
bin/console sync <tabla>                  # sincroniza
bin/console sync <tabla> --order-column id # incremental por columna
bin/console sync <tabla> --create-table   # crea la tabla en BigQuery si no existe
bin/console sync <tabla> --delete-table   # borra y recrea (full dump)
```

## Convenciones

- Estilo PSR-2 / PSR-4 (`MysqlToGoogleBigQuery\` → `src/`).
- Verifica sintaxis con `php -l <archivo>` tras editar.
- No introduzcas deprecations de PHP 8.1 (ver issue #7); de hecho, ayuda a eliminarlos.
- No subas secretos: `.env*` (excepto `.env.sample`) y `*-key.json` están en `.gitignore`.

## Tests (obligatorio)

Toda issue/PR debe entregar **tests unitarios** que cubran su cambio — es parte
del Definition of Done.

```bash
composer test        # corre la suite (PHPUnit)
```

- Los tests viven en `tests/` (namespace `MysqlToGoogleBigQuery\Tests\`).
- Aísla las dependencias externas (`BigQuery`, `Mysql`) con mocks/dobles; no se
  golpean servicios reales en los tests unitarios.
- Prioriza cubrir la lógica pura (p.ej. `SyncService::processRow()`).
- La infraestructura de testing se monta en la issue #10 (si aún no existe `composer test`,
  esa issue es prerequisito).

## Flujo de trabajo

- Se trabaja en local y se hace `git push origin master` al fork.
- El backlog vive en **GitHub Issues** (label `backlog`). Antes de implementar algo,
  revisa si ya hay una issue y enláza el commit/PR a ella.
- Mensajes de commit en español, concisos y descriptivos.
