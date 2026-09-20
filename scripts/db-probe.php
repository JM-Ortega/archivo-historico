<?php

/*
 * Detector de estado de la base de datos (read-only).
 *
 * Observa la BD por protocolo MySQL normal (PDO) y la clasifica; no escribe,
 * no reintenta y no depende de Docker ni de la CLI de mysql.
 *
 * Configuración (mismos nombres que usa AtoM, por entorno):
 *   ATOM_MYSQL_DSN        p. ej. mysql:host=percona;port=3306;dbname=atom;charset=utf8mb4
 *   ATOM_MYSQL_USERNAME
 *   ATOM_MYSQL_PASSWORD
 *
 * Salida: stdout = nombre del estado (una línea); stderr = detalle legible.
 *
 *   0  DB_COMPATIBLE       AtoM reconocible y schema == EXPECTED_SCHEMA
 *  10  DB_FRESH            BD accesible sin tablas
 *  20  DB_UNKNOWN          BD no vacía, no reconocible con seguridad como AtoM
 *  21  DB_SCHEMA_MISMATCH  AtoM reconocible, schema != EXPECTED_SCHEMA
 *  30  DB_UNREACHABLE      no se pudo conectar
 *
 * Fuera del contrato de estados (para que no se confundan con uno):
 *  64  configuración ausente o inválida (variables faltantes, DSN sin dbname)
 *  70  error inesperado tras conectar
 */

// Versión de schema de AtoM v2.10.2 (lib/task/migrate/migrations/arMigration0197.class.php).
const EXPECTED_SCHEMA = 197;

// Tablas mínimas que identifican una BD de AtoM.
const ATOM_TABLES = ['object', 'setting', 'setting_i18n', 'user'];

const CONNECT_TIMEOUT_SECONDS = 5;

// SQLSTATE de tabla/columna inexistente: la BD tiene una tabla con ese nombre pero no la de AtoM.
const SQLSTATE_NOT_ATOM = ['42S02', '42S22'];

function finish(string $state, int $code, string $detail = ''): never
{
    fwrite(STDOUT, $state."\n");
    if ('' !== $detail) {
        fwrite(STDERR, $detail."\n");
    }

    exit($code);
}

$dsn = getenv('ATOM_MYSQL_DSN');
$user = getenv('ATOM_MYSQL_USERNAME');
$pass = getenv('ATOM_MYSQL_PASSWORD');

if (false === $dsn || '' === $dsn || false === $user || false === $pass) {
    fwrite(STDERR, "db-probe: faltan ATOM_MYSQL_DSN, ATOM_MYSQL_USERNAME o ATOM_MYSQL_PASSWORD\n");

    exit(64);
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => CONNECT_TIMEOUT_SECONDS,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    finish('DB_UNREACHABLE', 30, 'no se pudo conectar: '.$e->getMessage());
}

try {
    // Defensa en profundidad: el servidor rechazará cualquier escritura de esta sesión.
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');

    // Sin base seleccionada (DSN sin dbname) no hay nada que observar: no es una BD vacía.
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || '' === $database) {
        fwrite(STDERR, "db-probe: el DSN no selecciona ninguna base de datos (falta dbname)\n");

        exit(64);
    }

    $tables = $pdo->query(
        'SELECT LOWER(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);

    if (0 === count($tables)) {
        finish('DB_FRESH', 10, 'BD accesible sin tablas');
    }

    $missing = array_diff(ATOM_TABLES, $tables);
    if ([] !== $missing) {
        finish('DB_UNKNOWN', 20, sprintf('BD con %d tablas; faltan tablas de AtoM: %s', count($tables), implode(', ', $missing)));
    }

    // Igual que arUpgradeSqlTask/tools:get-version: setting "version", valor en la cultura fuente.
    try {
        $versions = $pdo->query(
            'SELECT si.value FROM setting s
             JOIN setting_i18n si ON si.id = s.id AND si.culture = s.source_culture
             WHERE s.name = \'version\''
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        if (in_array($e->getCode(), SQLSTATE_NOT_ATOM, true)) {
            finish('DB_UNKNOWN', 20, 'las tablas de AtoM no tienen la estructura esperada: '.$e->getMessage());
        }

        throw $e;
    }

    if (1 !== count($versions) || !is_string($versions[0]) || 1 !== preg_match('/^[0-9]+$/', $versions[0])) {
        finish('DB_UNKNOWN', 20, 'setting "version" ausente, duplicado o no numérico');
    }

    $schema = (int) $versions[0];
    if (EXPECTED_SCHEMA === $schema) {
        finish('DB_COMPATIBLE', 0, 'schema '.$schema);
    }

    finish('DB_SCHEMA_MISMATCH', 21, sprintf('schema %d, esperado %d', $schema, EXPECTED_SCHEMA));
} catch (PDOException $e) {
    fwrite(STDERR, 'db-probe: error inesperado: '.$e->getMessage()."\n");

    exit(70);
}
