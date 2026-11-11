<?php

namespace App\Actions;

use App\Enums\Category;
use App\Enums\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportTransactions
{
    /**
     * Import a stored CSV. Bank files without a kategori column stay
     * uncategorized. Ledger files that include kategori (and optionally
     * betalingsmåte / notat) keep those values. Unknown category names
     * become null so the row lands in the inbox. Duplicates on
     * user_id + booked_on + amount + payee are skipped.
     *
     * @return array{imported: int, skipped: int}
     */
    public function __invoke(User $user, string $path): array
    {
        $contents = Storage::disk('local')->get($path);

        if (! is_string($contents) || trim($contents) === '') {
            return ['imported' => 0, 'skipped' => 0];
        }

        $contents = $this->normalizeEncoding($contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            return ['imported' => 0, 'skipped' => 0];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $rows = array_map(fn (string $line): array => str_getcsv($line, $delimiter), $lines);
        $mapping = $this->detectColumns($rows);

        $imported = 0;
        $skipped = 0;
        $classifier = app(ClassifyFoodPurchase::class);

        foreach ($this->dataRows($rows, $mapping) as $row) {
            $date = $this->parseDate($row[$mapping['date']] ?? null);
            $amount = $this->parseAmount($row[$mapping['amount']] ?? null);
            $payee = trim((string) ($row[$mapping['payee']] ?? ''));

            if ($date === null || $amount === null || $payee === '') {
                $skipped++;

                continue;
            }

            $exists = Transaction::query()
                ->where('user_id', $user->id)
                ->whereDate('booked_on', $date->toDateString())
                ->where('amount', $amount)
                ->where('payee', $payee)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $category = $mapping['category'] !== null
                ? Category::tryFromLabel(isset($row[$mapping['category']]) ? (string) $row[$mapping['category']] : null)
                : null;

            $paymentMethod = $mapping['payment_method'] !== null
                ? PaymentMethod::tryFromLabel(isset($row[$mapping['payment_method']]) ? (string) $row[$mapping['payment_method']] : null)
                : null;

            $note = null;
            if ($mapping['note'] !== null) {
                $note = trim((string) ($row[$mapping['note']] ?? ''));
                $note = $note === '' ? null : $note;
            }

            $needsReview = false;

            if (! $category instanceof Category) {
                $classification = $classifier($user, $payee, $note);
                $category = $classification->category;
                $needsReview = $classification->needsReview;
            }

            Transaction::query()->create([
                'user_id' => $user->id,
                'booked_on' => $date,
                'amount' => $amount,
                'payee' => $payee,
                'category' => $category,
                'payment_method' => $paymentMethod,
                'note' => $note,
                'needs_review' => $needsReview,
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @param  array{date: int, amount: int, payee: int, category: int|null, payment_method: int|null, note: int|null, has_header: bool}  $mapping
     * @return list<list<string|null>>
     */
    private function dataRows(array $rows, array $mapping): array
    {
        if ($mapping['has_header']) {
            array_shift($rows);
        }

        return $rows;
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @return array{date: int, amount: int, payee: int, category: int|null, payment_method: int|null, note: int|null, has_header: bool}
     */
    private function detectColumns(array $rows): array
    {
        $header = $rows[0] ?? [];
        $normalized = array_map(
            fn (mixed $value): string => Str::of((string) $value)->lower()->trim()->ascii()->toString(),
            $header,
        );

        $date = $this->firstColumn($normalized, [
            'date', 'dato', 'booked_on', 'booked', 'booking date', 'transaksjonsdato',
            'bokfort', 'posted', 'valutadato', 'valuedate',
            'transaction date', 'posting date',
        ]);
        $amount = $this->firstColumn($normalized, [
            'amount', 'belop', 'sum', 'amount_nok', 'verdi', 'amount nok',
        ]);
        $payee = $this->firstColumn($normalized, [
            'payee', 'text', 'tekst', 'beskrivelse', 'brukersted', 'merchant',
            'description', 'melding', 'navn', 'til/fra',
        ]);
        $category = $this->firstColumn($normalized, [
            'kategori', 'category',
        ]);
        $paymentMethod = $this->firstColumn($normalized, [
            'betalingsmate', 'payment_method', 'payment method', 'betalingsmaate',
        ]);
        $note = $this->firstColumn($normalized, [
            'notat', 'note', 'notes',
        ]);

        $hasHeader = $date !== null || $amount !== null || $payee !== null || $category !== null;

        $sample = $hasHeader ? ($rows[1] ?? []) : ($rows[0] ?? []);

        foreach ($sample as $index => $value) {
            if ($date === null && $this->parseDate($value) !== null) {
                $date = $index;
            } elseif ($amount === null && $this->parseAmount($value) !== null && $this->parseDate($value) === null) {
                $amount = $index;
            } elseif ($payee === null && is_string($value) && trim($value) !== '' && $this->parseAmount($value) === null && $this->parseDate($value) === null) {
                $payee = $index;
            }
        }

        return [
            'date' => $date ?? 0,
            'amount' => $amount ?? 1,
            'payee' => $payee ?? 2,
            'category' => $category,
            'payment_method' => $paymentMethod,
            'note' => $note,
            'has_header' => $hasHeader,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $needles
     */
    private function firstColumn(array $headers, array $needles): ?int
    {
        foreach ($headers as $index => $name) {
            foreach ($needles as $needle) {
                if ($name === $needle || str_contains($name, $needle)) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($candidates);

        return array_key_first($candidates);
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);

                if ($date instanceof CarbonImmutable && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
            }
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseAmount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        $raw = str_replace(["\u{00A0}", ' '], '', $raw);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d{1,2}$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',') && ! str_contains($raw, '.')) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return number_format(abs((float) $raw), 2, '.', '');
    }

    private function normalizeEncoding(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }
}
