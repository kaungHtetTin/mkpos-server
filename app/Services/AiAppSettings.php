<?php

namespace App\Services;

use App\Http\Controllers\Api\SettingsController;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Closed allowlist: never reads credentials, security or staff settings. */
class AiAppSettings
{
    public function defaults(): array
    {
        return array_diff_key(SettingsController::DEFAULTS, ['price_types' => true]);
    }

    private function rows()
    {
        return DB::table('settings')->where('business_id', app(TenantContext::class)->requireId());
    }

    public function read(): array
    {
        return ['values' => $this->values(), 'fields' => $this->fields(array_keys($this->defaults())),
            'operations' => ['create', 'update', 'delete', 'reset'],
            'delete_semantics' => 'Delete/reset restores the displayed application default. Required business name and currency cannot be deleted.',
            'excluded' => 'Account security, credentials, PINs, staff/role assignments, price-type rules, storefront configuration and image uploads are not managed by this tool.'];
    }

    private function values(): array
    {
        $values = array_replace($this->defaults(), $this->rows()->whereIn('key', array_keys($this->defaults()))->pluck('value', 'key')->all(),
            app(BusinessProfile::class)->read(app(TenantContext::class)->requireId()));

        return array_map(fn ($v) => (string) ($v ?? ''), $values);
    }

    public function fields(array $keys): array
    {
        $rules = SettingsController::rules();
        $fields = [];
        foreach ($keys as $key) {
            $field = ['key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'type' => 'text', 'maxLength' => 2000];
            foreach ($rules[$key] as $rule) {
                if (str_starts_with($rule, 'in:')) {
                    $field['type'] = 'select';
                    $field['options'] = explode(',', substr($rule, 3));
                }
                if (in_array($rule, ['numeric', 'integer'])) {
                    $field['type'] = 'number';
                    $field['step'] = $rule === 'integer' ? '1' : 'any';
                }
                if (str_starts_with($rule, 'between:')) {
                    [$field['min'], $field['max']] = explode(',', substr($rule, 8));
                }
                if (str_starts_with($rule, 'max:')) {
                    $field['maxLength'] = (int) substr($rule, 4);
                }
            }
            if (str_contains($key, '_show_')) {
                $field['type'] = 'select';
                $field['options'] = ['1', '0'];
            }
            if (in_array($key, ['shop_address', 'receipt_footer'])) {
                $field['type'] = 'textarea';
            }
            $fields[] = $field;
        }

        return $fields;
    }

    public function baseline(): array
    {
        $id = app(TenantContext::class)->requireId();

        return ['stored' => $this->rows()->whereIn('key', array_keys($this->defaults()))->orderBy('key')->lockForUpdate()->pluck('value', 'key')->all(),
            'profile' => (array) DB::table('businesses')->where('id', $id)->first(['name', 'currency']),
            'store' => (array) DB::table('storefront_settings')->where('business_id', $id)->first(['display_name', 'phone'])];
    }

    public function build(array $input): array
    {
        abort_if(array_diff(array_keys($input), ['action', 'values', 'keys']), 422, 'Unsupported settings arguments.');
        $data = validator($input, ['action' => 'required|in:create,update,delete,reset', 'values' => 'sometimes|array|min:1|max:40', 'keys' => 'sometimes|array|min:1|max:40', 'keys.*' => 'required|string|distinct'])->validate();
        $reset = in_array($data['action'], ['delete', 'reset']);
        abort_unless($reset ? isset($data['keys']) && ! isset($data['values']) : isset($data['values']) && ! isset($data['keys']), 422, 'Use values for create/update, or keys for delete/reset.');
        $keys = $reset ? $data['keys'] : array_keys($data['values']);
        abort_if(array_diff($keys, array_keys($this->defaults())), 422, 'A requested setting is outside the allowed app settings. Security and staff settings are blocked.');
        abort_if($reset && array_intersect($keys, ['shop_name', 'currency']), 422, 'Business name and currency must be updated, not deleted or reset.');
        $baseline = $this->baseline();
        abort_if($data['action'] === 'create' && array_intersect($keys, array_keys($baseline['stored'])), 422, 'This setting already exists. Use update.');
        $after = $reset ? array_intersect_key($this->defaults(), array_flip($keys)) : $data['values'];
        $rules = [];
        foreach ($after as $key => &$value) {
            abort_unless(is_string($value) || is_numeric($value) || is_bool($value), 422, 'Settings values must be scalar.');
            $value = trim((string) (is_bool($value) ? (int) $value : $value));
            $rules[$key] = array_values(array_diff(SettingsController::rules()[$key], ['sometimes', 'nullable']));
            if (str_contains($key, '_show_')) {
                $rules[$key] = ['required', 'in:0,1'];
            }
            if (in_array($key, ['shop_name', 'currency', 'payment_methods'])) {
                $rules[$key][] = 'required';
            }
            $rules[$key][] = 'max:'.(in_array('numeric', $rules[$key]) || in_array('integer', $rules[$key]) ? '1000000' : '2000');
        }
        unset($value);
        validator($after, $rules)->validate();
        $before = array_intersect_key($this->values(), $after);
        abort_if($before == $after && ! $reset, 422, 'These settings are already saved.');

        return ['payload' => ['action' => $data['action'], 'values' => $after], 'baseline' => $baseline,
            'preview' => [['name' => ucfirst($data['action']).' app settings', 'before' => $before, 'after' => $after,
                'fields' => $this->fields($keys), 'editable' => ! $reset,
                'notice' => $reset ? 'Restore these settings to the shown defaults.' : (isset($after['currency']) ? 'Currency changes the display label; existing amounts are not converted.' : (isset($after['shop_name']) ? 'Business name changes also update the business and online shop display name.' : 'Only the listed app settings will change.'))]]];
    }

    public function execute(array $payload): array
    {
        // Revalidate the stored payload against the current allowlist, even at execution.
        abort_if(array_diff(array_keys($payload['values']), array_keys($this->defaults())), 422);
        foreach ($payload['values'] as $key => $value) {
            if (in_array($payload['action'], ['delete', 'reset'])) {
                $this->rows()->where('key', $key)->delete();
            } else {
                DB::table('settings')->updateOrInsert(['business_id' => app(TenantContext::class)->requireId(), 'key' => $key], ['value' => $value]);
            }
        }
        app(BusinessProfile::class)->update(app(TenantContext::class)->requireId(), $payload['values']);

        return ['settings' => $payload['values'], 'modules' => ['settings', 'shop', 'sell', 'reports', 'products']];
    }
}
