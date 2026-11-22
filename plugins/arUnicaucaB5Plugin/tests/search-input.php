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
$check('longitud acotada (básica: 250)', 250, mb_strlen($s(['query' => str_repeat('á', 5000)])['query']));
$check('250 exactos se conservan', 250, mb_strlen($s(['query' => str_repeat('á', 250)])['query']));
$check('criterio avanzado acotado (200)', 200, mb_strlen($s(['sq0' => str_repeat('á', 5000)])['sq0']));
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

// KAN-163: el « -24» de una signatura se leía como «excluir 24» y la búsqueda no encontraba nada (99 % del catálogo).
// Un paréntesis que solo EMPIEZA como Ind/Col/Rep (Colombia, Colonial, Independencia, República...) es texto normal, no una signatura.
$check('texto con (Colombia) no se vuelve frase', ['query' => 'carta oficio (Colombia) 1850'], $s(['query' => 'carta oficio (Colombia) 1850']));
$check('texto con (Independencia) no se vuelve frase', ['query' => 'Gobernación (Independencia)'], $s(['query' => 'Gobernación (Independencia)']));
$check('texto con (Colonial) no se vuelve frase', ['query' => 'acta (Colonial)'], $s(['query' => 'acta (Colonial)']));
$check('texto con (República) no se vuelve frase', ['query' => 'decreto (República)'], $s(['query' => 'decreto (República)']));
$check('avanzada, otro campo: (Reparto de tierras) no se vuelve frase', ['sq0' => 'reparto (Reparto de tierras)', 'sf0' => 'scopeAndContent'], $s(['sq0' => 'reparto (Reparto de tierras)', 'sf0' => 'scopeAndContent']));
$check('una signatura Rep. sí se vuelve frase', ['query' => '"3 (Rep. C I -1 a)"'], $s(['query' => '3 (Rep. C I -1 a)']));
$check('signatura con espacio tras el paréntesis sigue siendo signatura', ['query' => '"9 ( Ind. C I -1 a)"'], $s(['query' => '9 ( Ind. C I -1 a)']));
echo "Signaturas (se buscan como frase literal)\n";
$sig = '5640 (Ind. C II -24 mn)';
$check('básica: signatura completa va entre comillas', ['query' => '"'.$sig.'"'], $s(['query' => $sig]));
$check('básica: signatura con sufijo (bis)', ['query' => '"9022 (Ind. C II -24 mn) b"'], $s(['query' => '9022 (Ind. C II -24 mn) b']));
$check('avanzada, cualquier campo: signatura completa', ['sq0' => '"'.$sig.'"'], $s(['sq0' => $sig, 'sf0' => ''])); // un sfN vacío ya se descartaba
$check('avanzada, sfN antes de sqN: mismo resultado', ['sf0' => 'identifier', 'sq0' => '"9052"'], $s(['sf0' => 'identifier', 'sq0' => '9052']));
$check('campo Signatura: siempre frase, aunque sea solo el número', ['sq0' => '"9052"', 'sf0' => 'identifier'], $s(['sq0' => '9052', 'sf0' => 'identifier']));
$check('campo Signatura: criterio vacío sigue vacío', ['sq0' => '', 'sf0' => 'identifier'], $s(['sq0' => '  ', 'sf0' => 'identifier']));
$check('campo Signatura: las comillas internas se quitan', ['sq0' => '"9052"', 'sf0' => 'identifier'], $s(['sq0' => '90"52', 'sf0' => 'identifier']));
$check('signatura ya entre comillas no se vuelve a envolver', ['query' => '"'.$sig.'"'], $s(['query' => '"'.$sig.'"']));
$check('criterio sqN de otro campo, no signatura: sin cambios', ['sq0' => 'carta -oficio', 'sf0' => 'title'], $s(['sq0' => 'carta -oficio', 'sf0' => 'title']));
$check('texto normal con guion no se toca', ['query' => 'carta -oficio'], $s(['query' => 'carta -oficio']));
$check('paréntesis que no son de signatura no se tocan', ['query' => '(carta OR oficio)'], $s(['query' => '(carta OR oficio)']));
$check('signatura en el campo Título (título = signatura)', ['sq1' => '"'.$sig.'"', 'sf1' => 'title'], $s(['sq1' => $sig, 'sf1' => 'title']));
$check('signatura con prefijo Col. (Colonia)', ['query' => '"708 (Col. C I -1 a)"'], $s(['query' => '708 (Col. C I -1 a)']));
$check('campo Signatura: el comodín de prefijo se respeta', ['sq0' => '705*', 'sf0' => 'identifier'], $s(['sq0' => '705*', 'sf0' => 'identifier']));
$check('signatura con comodín en la básica se respeta', ['query' => '5640 (Ind. C II -2*'], $s(['query' => '5640 (Ind. C II -2*']));
$check('campo Signatura sobre el máximo: 200 del usuario + 2 comillas', 202, mb_strlen($s(['sq0' => str_repeat('á', 5000), 'sf0' => 'identifier'])['sq0']));

echo "Resto de parámetros\n";
$check('otros parámetros no se tocan', ['page' => '2', 'sort' => 'relevance', 'levels' => '123'], $s(['page' => '2', 'sort' => 'relevance', 'levels' => '123']));
$check('query tras sqN no hereda estado', ['sq0' => 'z', 'query' => 'q'], $s(['sq0' => 'z', 'query' => 'q']));

echo $fail ? "\n{$fail} caso(s) fallaron\n" : "\nTodos los casos pasaron\n";
exit($fail ? 1 : 0);
