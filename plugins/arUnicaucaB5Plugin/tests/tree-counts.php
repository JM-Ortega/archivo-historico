<?php
// Casos unitarios de las funciones puras de arUnicaucaTreeCounts (KAN-139): plural, etiqueta y formato.
// Se ejecuta con tests/test-tree-counts.sh. Las partes con base de datos (conteos, insignia) se comprueban en el runtime.
require __DIR__.'/../lib/arUnicaucaTreeCounts.class.php';

$fails = 0;
$check = function (string $name, $got, $want) use (&$fails) {
    $ok = $got === $want;
    echo ($ok ? 'ok' : 'FALLA').": {$name}".($ok ? '' : ' (obtenido '.var_export($got, true).', esperado '.var_export($want, true).')')."\n";
    $fails += $ok ? 0 : 1;
};
$T = 'arUnicaucaTreeCounts';

foreach (['Fondo' => 'Fondos', 'Serie' => 'Series', 'Subserie' => 'Subseries', 'Sección' => 'Secciones', 'Asunto' => 'Asuntos',
    'Colección' => 'Colecciones', 'Expediente' => 'Expedientes', 'Unidad documental' => 'Unidades documentales',
    'Colección facticia' => 'Colecciones facticias', 'Pieza' => 'Piezas', 'Item' => 'Items', 'Ítem' => 'Ítems', 'Lápiz' => 'Lápices'] as $one => $many) {
    $check("plural «{$one}»", $T::plural($one, 'es'), $many);
}
$check('plural en inglés', $T::plural('Series', 'en'), 'Series');
$check('plural vacío', $T::plural('', 'es'), '');

$check('1 Fondo (singular con 1)', $T::label(1, 'Fondo', 'es'), '1 Fondo');
$check('0 Fondos (plural con 0)', $T::label(0, 'Fondo', 'es'), '0 Fondos');
$check('5 Subseries', $T::label(5, 'Subserie', 'es'), '5 Subseries');
$check('32 Secciones', $T::label(32, 'Sección', 'es'), '32 Secciones');
$check('7.496 Asuntos (miles con punto)', $T::label(7496, 'Asunto', 'es'), '7.496 Asuntos');
$check('formato en inglés', $T::format(7496, 'en'), '7,496');

echo $fails ? "tree-counts: {$fails} FALLAS\n" : "tree-counts: todo ok\n";
exit($fails ? 1 : 0);
