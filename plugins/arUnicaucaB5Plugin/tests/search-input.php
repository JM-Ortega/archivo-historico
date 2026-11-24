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
$check('HTML se conserva salvo la barra (se escapa al mostrar)', ['query' => '<b>x< b>'], $s(['query' => '<b>x</b>']));
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

echo "Sintaxis de consulta mal formada (no debe llegar a Elasticsearch)\n";
$n = ['arUnicaucaSearchInput', 'neutralizeSyntax'];
$check('barra suelta (abría una expresión regular)', '1 2', $n('1/2'));
$check('barra en una palabra', 'Cabildo Popayán', $n('Cabildo/Popayán'));
$check('fecha con barras', '12 03 1850', $n('12/03/1850'));
$check('cierre de etiqueta HTML', '<b>prueba< b>', $n('<b>prueba</b>'));
$check('paréntesis de apertura sin cierre', 'prueba', $n('(prueba'));
$check('paréntesis de cierre sin apertura', 'prueba', $n('prueba)'));
$check('paréntesis con pareja se conservan (agrupación)', '(a OR b)', $n('(a OR b)'));
$check('comilla sin pareja', 'prueba', $n('"prueba'));
$check('paréntesis vacíos', '', $n('()'));
$check('paréntesis vacíos anidados', '', $n('(())'));
$check('operador pegado al paréntesis de apertura', 'a', $n('(AND a)'));
$check('operador pegado al paréntesis de cierre', 'a', $n('(a AND)'));
$check('frase entre comillas se conserva', '"cabildo" AND popayán*', $n('"cabildo" AND popayán*'));
$check('barra dentro de una frase entre comillas se conserva', '"a/b" y c d', $n('"a/b" y c/d'));
$check('dos puntos', 'prueba', $n('prueba:'));
$check('barra invertida', 'prueba', $n('prueba\\'));
$check('acento circunflejo', 'a 2', $n('a^2'));
$check('signo de admiración', 'a', $n('!a'));
$check('corchetes', 'a', $n('[a]'));
$check('llaves', 'a', $n('{a}'));
$check('operador al final', 'prueba', $n('prueba AND'));
$check('operador al principio', 'a', $n('AND a'));
$check('solo un operador queda vacío', '', $n('NOT'));
$check('operadores repetidos al final', 'a', $n('a AND AND'));
$check('operador final tras otro operador', 'a', $n('a OR NOT'));
$check('operador bien colocado se conserva', 'a AND NOT b', $n('a AND NOT b'));
$check('tilde (difusa) se quita', 'prueba', $n('prueba~'));
$check('tilde suelta: queda el comodín', '*', $n('~*'));
$check('operadores && y || se quitan', 'a b', $n('a && b'));
$check('+ o - sueltos se quitan', 'a b', $n('a - b + '));
$check('prefijo -palabra se conserva', 'a -b', $n('a -b'));
$check('prefijo +palabra se conserva', '+a b', $n('+a b'));
$check('guion dentro de una palabra se conserva', 'a-b', $n('a-b'));
$check('prefijo pegado a un operador', 'a', $n('-OR a'));
$check('frase pegada a un operador', '"a b" 2', $n('AND"a b"~2'));
$check('prefijo sobre una frase se conserva', '-"a b" c', $n('-"a b" c'));
$check('dos operadores seguidos: se queda el último', 'a OR b', $n('a AND OR b'));
$check('AND NOT seguido es válido', 'a AND NOT b', $n('a AND NOT b'));
$check('NOT AND seguido se reduce al último', 'a AND b', $n('a NOT AND b'));
$check('doble negación NOT -a: sobra el prefijo', 'a NOT a', $n('a NOT -a'));
$check('AND -a es válido y se conserva', 'a AND -b', $n('a AND -b'));
// Frases entre comillas: nada se mete dentro de las comillas y las agrupaciones con frases se conservan (revisión de código).
$check('dos frases con un operador en medio', '"cabildo" AND "popayán"', $n('"cabildo" AND "popayán"'));
$check('dos frases con OR', '"a b" OR "c d"', $n('"a b" OR "c d"'));
$check('dos frases seguidas', '"a" "b"', $n('"a" "b"'));
$check('frases entre palabras', 'x "a b" y "c d" z', $n('x "a b" y "c d" z'));
$check('agrupación con frases se conserva', 'x AND ("a" OR "b")', $n('x AND ("a" OR "b")'));
$check('una frase entre paréntesis se conserva', '("a b")', $n('("a b")'));
$check('agrupación con dos frases y otro término', '("Cabildo de Popayán" OR "Cabildo de Cali") AND colonial', $n('("Cabildo de Popayán" OR "Cabildo de Cali") AND colonial'));
$check('frase con prefijo de exclusión dentro de un grupo', '(x -"a b")', $n('(x -"a b")'));
// NOT al principio: niega (Elasticsearch devuelve todo salvo el término); no se descarta.
$check('NOT al principio se conserva', 'NOT oficio', $n('NOT oficio'));
$check('NOT al principio con frase', 'NOT "a b"', $n('NOT "a b"'));
$check('AND al principio se sigue descartando', 'oficio', $n('AND oficio'));
$check('OR al principio se sigue descartando', 'oficio', $n('OR oficio'));
$check('NOT -a al principio: sobra el prefijo', 'NOT a', $n('NOT -a'));
$check('AND NOT al principio queda como NOT', 'NOT a', $n('AND NOT a'));
$check('comodines * y ? se conservan', 'pru*eba p?ueba', $n('pru*eba p?ueba'));
$check('texto normal intacto', 'casa colonial', $n('casa colonial'));
$check('vacío se conserva vacío', '', $n(''));
$check('signatura literal entre comillas intacta', '"9052 (Ind. J II -3 cr)"', $n('"9052 (Ind. J II -3 cr)"'));
$check('la básica pasa por la neutralización', ['query' => '1 2'], $s(['query' => '1/2']));
$check('la avanzada pasa por la neutralización', ['sq0' => 'a', 'sf0' => 'scopeAndContent'], $s(['sq0' => 'a:', 'sf0' => 'scopeAndContent']));
$check('signatura pegada completa sigue como frase literal', ['sq0' => '"9052 (Ind. J II -3 cr)"', 'sf0' => 'identifier'], $s(['sq0' => '9052 (Ind. J II -3 cr)', 'sf0' => 'identifier']));

// Fuera de la búsqueda (autocompletes, árbol, etc.) solo se limpia el texto: controles y longitud; no se reescribe la sintaxis.
$o = ['arUnicaucaSearchInput', 'sanitize'];
$check('otra ruta: la barra no se toca', ['query' => 'Cabildo/Popayán'], $o(['query' => 'Cabildo/Popayán'], false));
$check('otra ruta: una signatura no se vuelve frase', ['query' => '9052 (Ind. J II -3 cr)'], $o(['query' => '9052 (Ind. J II -3 cr)'], false));
$check('otra ruta: el operador inicial no se toca', ['query' => 'NOT x'], $o(['query' => 'NOT x'], false));
$check('otra ruta: sí se limpian los controles', ['query' => 'a b'], $o(['query' => "a\0\x07b"], false));
$check('otra ruta: sí se acota la longitud', 250, mb_strlen($o(['query' => str_repeat('á', 5000)], false)['query']));
$check('otra ruta: sqN se limpia pero no se reescribe', ['sq0' => 'a/b'], $o(['sq0' => 'a/b'], false));
$check('isSearchRoute: la búsqueda', true, arUnicaucaSearchInput::isSearchRoute('/index.php/informationobject/browse'));
$check('isSearchRoute: la búsqueda con barra final', true, arUnicaucaSearchInput::isSearchRoute('/informationobject/browse/'));
$check('isSearchRoute: un autocompletar no', false, arUnicaucaSearchInput::isSearchRoute('/index.php/informationobject/autocomplete'));
$check('isSearchRoute: autocompletar de actores no', false, arUnicaucaSearchInput::isSearchRoute('/index.php/actor/autocomplete'));
$check('isSearchRoute: vacío no', false, arUnicaucaSearchInput::isSearchRoute(''));
$check('la ruta de búsqueda (por defecto) sí reescribe', ['query' => 'Cabildo Popayán'], $o(['query' => 'Cabildo/Popayán']));
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
// KAN-163: el paréntesis sin cerrar de un prefijo de signatura se neutraliza (antes tampoco encontraba la ficha: 0 resultados).
$check('prefijo de signatura con comodín en la básica: el comodín se respeta', ['query' => '5640 Ind. C II -2*'], $s(['query' => '5640 (Ind. C II -2*']));
$check('campo Signatura sobre el máximo: 200 del usuario + 2 comillas', 202, mb_strlen($s(['sq0' => str_repeat('á', 5000), 'sf0' => 'identifier'])['sq0']));

echo "Resto de parámetros\n";
$check('otros parámetros no se tocan', ['page' => '2', 'sort' => 'relevance', 'levels' => '123'], $s(['page' => '2', 'sort' => 'relevance', 'levels' => '123']));
$check('query tras sqN no hereda estado', ['sq0' => 'z', 'query' => 'q'], $s(['sq0' => 'z', 'query' => 'q']));

echo $fail ? "\n{$fail} caso(s) fallaron\n" : "\nTodos los casos pasaron\n";
exit($fail ? 1 : 0);
