<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class McpProductImportValidator
{
    private const HEADERS = ['Name', 'SKU', 'Barcode', 'Category', 'Base Unit', 'Purchase Unit', 'Units Per Purchase Unit', 'Cost', 'Stock', 'Low Stock Alert'];
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const ALIASES = ['base_unit', 'purchase_unit', 'purchase_conversion_factor', 'low_stock_alert', 'low_stock_threshold', 'price'];

    public function __construct(private ProductImportService $imports, private ProductWriter $writer) {}

    public function template(array $data): array
    {
        $types = $this->types($data['price_types'] ?? '');
        $headers = [...self::HEADERS, ...$types];
        $example = ['Example product', 'SKU-001', '001234567890', 'General', 'Piece', 'Box', '12', '1000', '24', '5', ...array_fill(0, count($types), '1500')];
        return ['filename' => 'mkpos-product-import-template.csv', 'encoding' => 'UTF-8', 'delimiter' => ',',
            'headers' => $headers, 'required_headers' => ['Name'], 'price_types' => $types,
            'template_csv' => $this->csv([$headers]), 'example_csv' => $this->csv([$headers, $example]),
            'max_bytes' => self::MAX_BYTES, 'max_product_rows' => ProductImportService::MAX_ROWS,
            'rules' => [
                'Name is required on every product row; name, SKU, barcode and category support up to 255 characters.',
                'Cost and selling prices are whole numbers of at least zero. Stock may be fractional or negative; low-stock thresholds must be at least zero.',
                'Unit names support up to 50 characters. A purchase unit must differ from the base unit and its conversion must be greater than one, at most 1000000.',
                'The default base unit is Unit; missing numeric values default to zero and missing conversion defaults to one.',
                'Keep SKU/barcode cells as text to preserve leading zeros. Quote values containing commas, quotes or newlines; double embedded quotes.',
                'Declare extra selling-price names through price_types. Unknown headings are flagged to avoid silently discarded values.',
            ], 'database_checked' => false, 'writes_performed' => false,
            'note' => 'This is a template, not an import. The signed-in app performs business/catalog checks before creating products. Existing products are not updated.'];
    }

    public function check(array $data): array
    {
        $csv = $data['csv_content']; $types = $this->types($data['price_types'] ?? '');
        $base = ['validation_scope' => 'public_file_only', 'database_checked' => false, 'writes_performed' => false,
            'content_sha256' => hash('sha256', $csv), 'price_types' => $types,
            'note' => 'Checks only the submitted file. Private catalog duplicates, actual business price configuration and import permission were not checked. Upload the corrected CSV in the signed-in Products import screen for authoritative server validation. Nothing was imported.'];
        $failure = fn ($field, $message) => $base + ['valid' => false, 'total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'duplicate_rows' => 0,
            'file_errors' => [['field' => $field, 'message' => $message]], 'rows' => [], 'has_more' => false, 'next_offset' => null];
        if (strlen($csv) > self::MAX_BYTES) return $failure('file', 'The CSV must be no larger than 5 MB.');
        if (isset($data['filename']) && (!preg_match('/\.csv$/i', $data['filename']) || str_contains($data['filename'], '/') || str_contains($data['filename'], '\\'))) return $failure('filename', 'Provide only a .csv filename, not an Excel workbook, local path or file URL.');
        if (!mb_check_encoding($csv, 'UTF-8')) return $failure('file', 'Save the CSV using UTF-8 encoding.');
        if (str_contains($csv, "\0")) return $failure('file', 'The CSV contains NUL bytes. Export it as UTF-8 text.');
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if ($error = $this->quotingError($csv)) return $failure('file', $error);
        $stream = fopen('php://temp', 'r+'); fwrite($stream, $csv); rewind($stream);
        try {
            $headers = fgetcsv($stream, 0, ',', '"', '');
            if (!$headers || $headers === [null]) return $failure('headers', 'Import file is empty.');
            $headers = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $headers);
            if (count($headers) > 100 || collect($headers)->contains(fn ($heading) => mb_strlen($heading) > 100)) return $failure('headers', 'Use at most 100 columns with headings up to 100 characters.');
            if (!in_array('name', $headers, true)) return $failure('headers', 'The CSV must contain a Name column.');
            if (in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) return $failure('headers', 'Column headings must be non-empty and unique.');
            $allowed = [...array_map('mb_strtolower', self::HEADERS), ...self::ALIASES];
            foreach ($types as $type) { $allowed[] = mb_strtolower($type); $allowed[] = mb_strtolower($type).' price'; }
            $unknown = array_values(array_diff($headers, $allowed));
            $fileErrors = $unknown ? [['field' => 'headers', 'message' => 'Unknown columns: '.implode(', ', $unknown).'. Use the template headings or declare extra price_types.']] : [];
            $aliasGroups = [['base unit', 'base_unit'], ['purchase unit', 'purchase_unit'], ['units per purchase unit', 'purchase_conversion_factor'], ['low stock alert', 'low_stock_alert', 'low_stock_threshold']];
            foreach ($types as $type) $aliasGroups[] = [mb_strtolower($type), mb_strtolower($type).' price'];
            foreach ($aliasGroups as $aliases) {
                $present = array_values(array_intersect($headers, $aliases));
                if (count($present) > 1) $fileErrors[] = ['field' => 'headers', 'message' => 'Ambiguous columns: '.implode(', ', $present).'. Keep one heading for this field.'];
            }
            if (in_array('price', $headers, true) && array_intersect($headers, array_map('mb_strtolower', [...$types, ...array_map(fn ($type) => $type.' price', $types)]))) {
                $fileErrors[] = ['field' => 'headers', 'message' => 'Price is ignored when named selling-price columns exist. Keep either Price or named price columns.'];
            }
            $rows = []; $seen = []; $number = 1;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $number++;
                if (!array_filter($cells, fn ($value) => trim((string) $value) !== '')) continue;
                if (count($rows) >= ProductImportService::MAX_ROWS) return $failure('file', 'Import at most 5000 products per file.');
                $payload = []; $errors = []; $duplicate = false;
                if (count($cells) !== count($headers)) $errors['row'] = ['The number of values does not match the column headings.'];
                else {
                    $payload = $this->imports->payload(array_combine($headers, array_map(fn ($value) => trim((string) $value), $cells)), $types);
                    try {
                        // Shared manual/import rules, deliberately excluding the
                        // catalog uniqueness query on this unauthenticated tool.
                        $payload = $this->writer->validate(Request::create('/', 'POST', $payload), null, false, false);
                    } catch (ValidationException $error) { $errors = $error->errors(); }
                    catch (HttpExceptionInterface $error) {
                        if ($error->getStatusCode() !== 422) throw $error;
                        $errors[str_contains($error->getMessage(), 'different') ? 'purchase_unit' : 'purchase_conversion_factor'] = [$error->getMessage()];
                    }
                    foreach (['sku', 'barcode'] as $field) {
                        $value = mb_strtolower((string) ($payload[$field] ?? ''));
                        if ($value === '') continue;
                        if (isset($seen[$field][$value])) { $duplicate = true; $errors[$field][] = 'This '.$field.' repeats row '.$seen[$field][$value].'. Keep a unique code or remove the duplicate row.'; }
                        else $seen[$field][$value] = $number;
                    }
                }
                $issues = [];
                foreach ($errors as $field => $messages) foreach ($messages as $message) $issues[] = ['field' => $field, 'message' => $message];
                $rows[] = ['row_number' => $number, 'name' => mb_substr((string) ($payload['name'] ?? ''), 0, 255), 'status' => $issues ? ($duplicate ? 'duplicate' : 'invalid') : 'valid',
                    'errors' => $issues, 'preview' => $issues ? null : $payload];
            }
            if (!$rows) return $failure('file', 'Import file has no product rows.');
        } finally { fclose($stream); }
        $invalid = count(array_filter($rows, fn ($row) => $row['status'] === 'invalid'));
        $duplicates = count(array_filter($rows, fn ($row) => $row['status'] === 'duplicate'));
        $selected = ($data['issues_only'] ?? true) ? array_values(array_filter($rows, fn ($row) => $row['errors'])) : $rows;
        $offset = (int) ($data['offset'] ?? 0); $limit = (int) ($data['limit'] ?? 50);
        return $base + ['valid' => !$invalid && !$duplicates && !$fileErrors, 'headers' => $headers, 'total_rows' => count($rows),
            'valid_rows' => count($rows) - $invalid - $duplicates, 'invalid_rows' => $invalid, 'duplicate_rows' => $duplicates,
            'file_errors' => $fileErrors, 'returned_rows' => count(array_slice($selected, $offset, $limit)), 'matching_rows' => count($selected),
            'rows' => array_slice($selected, $offset, $limit), 'has_more' => $offset + $limit < count($selected),
            'next_offset' => $offset + $limit < count($selected) ? $offset + $limit : null];
    }

    private function types(string $extra): array
    {
        $types = ['Retail', 'Wholesale', 'Credit'];
        $reserved = [...array_map('mb_strtolower', self::HEADERS), ...self::ALIASES];
        foreach (array_filter(array_map('trim', explode(',', $extra))) as $type) {
            if (mb_strlen($type) > 50 || preg_match('/[\r\n\x00]/', $type) || in_array(mb_strtolower($type), $reserved, true)) {
                throw ValidationException::withMessages(['price_types' => 'Price names must be at most 50 characters and must not conflict with product field headings.']);
            }
            if (!in_array(mb_strtolower($type), array_map('mb_strtolower', $types), true)) $types[] = $type;
        }
        if (count($types) > 20) throw ValidationException::withMessages(['price_types' => 'Use at most 20 selling-price types.']);
        return $types;
    }

    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        try { foreach ($rows as $row) fputcsv($stream, $row, ',', '"', '', "\r\n"); rewind($stream); return stream_get_contents($stream); }
        finally { fclose($stream); }
    }

    private function quotingError(string $csv): ?string
    {
        $quoted = false; $closed = false; $value = false; $row = 1; $length = strlen($csv);
        for ($i = 0; $i < $length; $i++) {
            $char = $csv[$i];
            if ($quoted) {
                if ($char === '"' && ($csv[$i + 1] ?? '') === '"') $i++;
                elseif ($char === '"') { $quoted = false; $closed = true; }
            } elseif ($char === ',') { $value = false; $closed = false; }
            elseif ($char === "\n" || $char === "\r") { $row++; $value = false; $closed = false; if ($char === "\r" && ($csv[$i + 1] ?? '') === "\n") $i++; }
            elseif ($char === '"' && !$value && !$closed) $quoted = true;
            elseif ($char === '"' || ($closed && trim($char) !== '')) return 'Invalid CSV quoting near row '.$row.'. Quote the whole value and double embedded quotes.';
            elseif (!$closed) $value = true;
        }
        return $quoted ? 'Unclosed quoted field near row '.$row.'. Close the field with a double quote.' : null;
    }
}
