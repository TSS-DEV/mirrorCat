<?php
require_once __DIR__ . '/include/xlsx.php';
require_once __DIR__ . '/include/auth.php';

auth_require_login();

/**
 * Stream a small sample workbook so the workflow can be tested without a
 * user-supplied spreadsheet.
 */

$headers = ['Title', 'Author', 'Publisher', 'Place', 'Year', 'Language', 'ISBN'];
$rows = [
    ['The midnight library', 'Matt Haig', 'Viking', 'New York', '2020', 'eng', '9780525559474'],
    ['Klara and the sun', 'Kazuo Ishiguro', 'Alfred A. Knopf', 'New York', '2021', 'eng', '9780593318171'],
    ['The book of form and emptiness', 'Ruth Ozeki', 'Viking', 'New York', '2021', 'eng', '9780670026648'],
    ['Cloud cuckoo land', 'Anthony Doerr', 'Scribner', 'New York', '2021', 'eng', '9781982168438'],
    ['On earth we\'re briefly gorgeous', 'Ocean Vuong', 'Penguin Press', 'New York', '2019', 'eng', '9780525562023'],
];

XlsxWriter::download('mirrorcat-sample.xlsx', $headers, $rows);
