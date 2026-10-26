<?php

namespace App\Services;

use App\Support\AppTime;

/** Validation shared by normal forms and the AI proposal flow. */
class BusinessRecordWriter
{
    public function validate(string $type, array $input): array
    {
        $rules = match ($type) {
            'customer' => ['name' => 'required|string|max:255', 'phone' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string', 'note' => 'nullable|string'],
            'supplier' => ['name' => 'required|string|max:255', 'phone' => 'nullable|string|max:255', 'address' => 'nullable|string', 'contact_person' => 'nullable|string|max:255', 'note' => 'nullable|string'],
            'expense' => ['title' => 'required|string|max:255', 'category' => 'nullable|string|max:255', 'amount' => 'required|integer|min:1', 'payment_method' => 'nullable|string|max:255', 'expense_date' => 'nullable|date', 'note' => 'nullable|string'],
        };
        $data = validator($input, $rules)->validate();
        if ($type === 'customer' && array_key_exists('email', $data)) {
            $data['email'] = ! empty($data['email']) ? strtolower(trim($data['email'])) : null;
        }
        foreach (match ($type) {
            'customer' => ['phone', 'address', 'note'], 'supplier' => ['phone', 'address', 'contact_person', 'note'], 'expense' => ['category']
        } as $field) {
            $data[$field] = $data[$field] ?? '';
        }
        if ($type === 'expense') {
            $data['payment_method'] = $data['payment_method'] ?? 'Cash';
            $data['expense_date'] = $data['expense_date'] ?? now(AppTime::businessTimezone())->toDateString();
        }

        return $data;
    }
}
