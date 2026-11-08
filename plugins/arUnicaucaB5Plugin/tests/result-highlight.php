<?php
// Casos unitarios de arUnicaucaResultHighlight (KAN-138). Se ejecuta con tests/test-result-highlight.sh.
require __DIR__.'/../lib/arUnicaucaResultHighlight.class.php';

$fails = 0;
$check = function (string $name, bool $ok) use (&$fails) {
    echo ($ok ? 'ok' : 'FALLA').": {$name}\n";
    $fails += $ok ? 0 : 1;
};
$mark = fn ($html, $terms) => arUnicaucaResultHighlight::mark($html, $terms);
$m = '<mark class="ah-hit">';

$check('palabras: sin operadores, comodines ni cortas', arUnicaucaResultHighlight::terms('Popayán AND "Quiñones" or x *') === ['Popayán', 'Quiñones']);
$check('palabras: tope de 8', 8 === count(arUnicaucaResultHighlight::terms('aa bb cc dd ee ff gg hh ii jj')));
$check('tilde indistinta', $mark('Cabildo de Popayan', ['Popayán']) === "Cabildo de {$m}Popayan</mark>");
$check('ñ escrita como n: «quinones» marca «Quiñones»', $mark('Carta de Quiñones al cabildo', ['quinones']) === "Carta de {$m}Quiñones</mark> al cabildo");
$check('n escrita como ñ: «Quiñones» marca «Quinones»', $mark('Carta de Quinones al cabildo', ['Quiñones']) === "Carta de {$m}Quinones</mark> al cabildo");
$check('ñ no marca palabras distintas: «ano» no marca «anotado»', $mark('anotado', ['ano']) === 'anotado');
$check('mayúsculas indistintas', $mark('POPAYÁN', ['popayán']) === "{$m}POPAYÁN</mark>");
$check('no toca etiquetas ni atributos', $mark('<a href="/popayan">Popayán</a>', ['popayan']) === "<a href=\"/popayan\">{$m}Popayán</mark></a>");
$check('palabra completa', $mark('Cauca caucano', ['cauca']) === "{$m}Cauca</mark> caucano");
$check('no marca dentro de entidades', 'Ruiz &amp; Co' === $mark('Ruiz &amp; Co', ['amp']));
$check('signatura', $mark('5640 (Ind. C II -24 mn) b', ['5640', 'mn']) === "{$m}5640</mark> (Ind. C II -24 {$m}mn</mark>) b");
$check('sin términos devuelve igual', 'x' === $mark('x', []));
$check('consulta con caracteres de regex', 'a.b (c)' === $mark('a.b (c)', arUnicaucaResultHighlight::terms('(( [a-z] ).*')));
exit($fails ? 1 : 0);
