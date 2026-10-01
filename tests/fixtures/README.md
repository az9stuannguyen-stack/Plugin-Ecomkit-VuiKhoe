# Synthetic Excel fixtures

`tests/excel-parser-check.php` creates XLSX fixtures at runtime with PhpSpreadsheet and deletes them after the test. The generated cases cover valid, missing-header, blank-ID, duplicate, long text ID, alphanumeric ID, leading-zero ID, unsafe/scientific numeric ID, invalid workbook, row-limit and multiple-worksheet behavior. No real customer or company data is stored in the repository.
