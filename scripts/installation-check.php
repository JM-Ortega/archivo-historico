<?php

/*
 * Comprobación read-only de instalación completa de AtoM.
 *
 * Complementa a db-probe.php (que solo dice si la BD es AtoM con el schema esperado): un
 * tools:install interrumpido después de escribir schema/version puede dejar una BD DB_COMPATIBLE
 * pero incompleta. La última escritura de tools:install es la membresía del administrador
 * (addSuperUser: QubitAclUserGroup con QubitAclGroup::ADMIN_ID = 100), así que su existencia
 * implica que todos los pasos anteriores terminaron.
 *
 * Invariante: existe al menos una fila en acl_user_group con group_id = 100.
 * Tabla o columna ausente = instalación incompleta. No escribe ni guarda estado propio.
 *
 * Configuración: ATOM_MYSQL_DSN, ATOM_MYSQL_USERNAME, ATOM_MYSQL_PASSWORD (como db-probe.php).
 * Salida: stdout = INSTALL_COMPLETE | INSTALL_INCOMPLETE; stderr = detalle.
 *
 *   0  INSTALL_COMPLETE
 *   1  INSTALL_INCOMPLETE
 *  64  configuración ausente o inválida
 *  70  error inesperado (incluye no poder conectar)
 */

// AtoM v2.10.2: plugins/qbAclPlugin/lib/model/QubitAclGroup.php (ADMIN_ID).
const ADMIN_GROUP_ID = 100;

// Tabla/columna inexistente: la estructura de AtoM no está completa.
const SQLSTATE_MISSING = ['42S02', '42S22'];

const CONNECT_TIMEOUT_SECONDS = 5;

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
    fwrite(STDERR, "installation-check: faltan ATOM_MYSQL_DSN, ATOM_MYSQL_USERNAME o ATOM_MYSQL_PASSWORD\n");

    exit(64);
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => CONNECT_TIMEOUT_SECONDS,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');

    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || '' === $database) {
        fwrite(STDERR, "installation-check: el DSN no selecciona ninguna base de datos (falta dbname)\n");

        exit(64);
    }

    try {
        $admins = (int) $pdo->query('SELECT COUNT(*) FROM acl_user_group WHERE group_id = '.ADMIN_GROUP_ID)->fetchColumn();
    } catch (PDOException $e) {
        if (in_array($e->getCode(), SQLSTATE_MISSING, true)) {
            finish('INSTALL_INCOMPLETE', 1, 'acl_user_group ausente o sin la estructura esperada: '.$e->getMessage());
        }

        throw $e;
    }

    // Informativo (no es requisito): los settings de sitio los escribe tools:install antes del admin.
    try {
        $siteBaseUrl = (int) $pdo->query("SELECT COUNT(*) FROM setting WHERE name = 'siteBaseUrl'")->fetchColumn() > 0 ? 'presente' : 'ausente';
    } catch (PDOException) {
        $siteBaseUrl = 'no legible';
    }
    $info = sprintf('membresías de administrador (grupo %d): %d; siteBaseUrl: %s (informativo)', ADMIN_GROUP_ID, $admins, $siteBaseUrl);

    if ($admins < 1) {
        finish('INSTALL_INCOMPLETE', 1, 'sin administrador: '.$info);
    }

    finish('INSTALL_COMPLETE', 0, $info);
} catch (PDOException $e) {
    fwrite(STDERR, 'installation-check: error inesperado: '.$e->getMessage()."\n");

    exit(70);
}
