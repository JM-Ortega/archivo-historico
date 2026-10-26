<?php

/*
 * Casos de arUnicaucaSearchInput (KAN-98/KAN-99). Lógica pura: no requiere sesión ni base de datos.
 * Se ejecuta con tests/test-search-input.sh.
 */
require_once __DIR__.'/../lib/arUnicaucaSearchInput.class.php';

$fail = 0;
$check = function (string $name, $expected, $actual) use (&$fail) {
    $ok = $expected === $actual;
    echo ($ok ? '  ok   ' : '  FAIL ').$name.($ok ? '' : ' -> esperado '.var_export($expected, true).', obtenido '.var_export($actual, true))."\n";
    $fail += $ok ? 0 : 1;
};
$s = ['arUnicaucaSearchInput', 'sanitize'];

echo "Búsqueda básica (query)\n";
$check('texto normal intacto', ['query' => 'Simón Bolívar'], $s(['query' => 'Simón Bolívar']));
$check('espacios colapsados y recortados', ['query' => 'a b c'], $s(['query' => "  a \t b\n c  "]));
$check('byte NUL y controles', ['query' => 'a b'], $s(['query' => "a\0\x07b"]));
$check('vacío se conserva vacío', ['query' => ''], $s(['query' => '   ']));
$check('array se descarta', [], $s(['query' => ['x']]));
$check('longitud acotada', 200, mb_strlen($s(['query' => str_repeat('á', 5000)])['query']));
$check('UTF-8 inválido se sustituye por ?', ['query' => 'a?b'], $s(['query' => "a\xC3b"]));
$check('HTML se conserva (se escapa al mostrar)', ['query' => '<b>x</b>'], $s(['query' => '<b>x</b>']));
$check('sintaxis de búsqueda se conserva', ['query' => '"cabildo" AND popayán*'], $s(['query' => '"cabildo" AND popayán*']));
$check('unicode combinado intacto', ['query' => 'ñandú ☃'], $s(['query' => 'ñandú ☃']));

echo "Búsqueda avanzada\n";
$check('sqN se limpia como término', ['sq0' => 'x y'], $s(['sq0' => "x\0 y"]));
$check('sqN array se descarta', [], $s(['sq1' => ['a']]));
$check('criterio sobre el máximo se descarta', ['sq19' => 'ok'], $s(['sq19' => 'ok', 'sq20' => 'no', 'sq99' => 'no']));
$check('soN válido en minúscula', ['so1' => 'or'], $s(['so1' => ' OR ']));
$check('soN inválido se descarta', [], $s(['so1' => 'xor']));
$check('sfN válido', ['sf0' => 'creators.i18n.es.authorizedFormOfName'], $s(['sf0' => 'creators.i18n.es.authorizedFormOfName']));
$check('sfN con símbolos se descarta', [], $s(['sf0' => 'title"; DROP']));
$check('fecha año', ['startDate' => '1850'], $s(['startDate' => '1850']));
$check('fecha completa', ['endDate' => '1900-12-31'], $s(['endDate' => '1900-12-31']));
$check('fecha inválida se descarta', [], $s(['startDate' => '1850<script>']));
$check('rangeType válido', ['rangeType' => 'exact'], $s(['rangeType' => 'exact']));
$check('rangeType inválido se descarta', [], $s(['rangeType' => 'x']));
$check('topLod booleano', ['topLod' => '0'], $s(['topLod' => '0']));
$check('topLod inválido se descarta', [], $s(['topLod' => 'yes<']));

echo "Resto de parámetros\n";
$check('otros parámetros no se tocan', ['page' => '2', 'sort' => 'relevance', 'levels' => '123'], $s(['page' => '2', 'sort' => 'relevance', 'levels' => '123']));
$check('query tras sqN no hereda estado', ['sq0' => 'z', 'query' => 'q'], $s(['sq0' => 'z', 'query' => 'q']));

echo $fail ? "\n{$fail} caso(s) fallaron\n" : "\nTodos los casos pasaron\n";
exit($fail ? 1 : 0);
