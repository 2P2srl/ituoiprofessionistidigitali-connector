<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Data;

use Spatie\LaravelData\Data;

/**
 * An address in Italy.
 */
final class AddressData extends Data
{
    public function __construct(
        public string $street,
        public string $postal_code,
        public string $municipality,
        public string $province,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            'municipality' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
        ];
    }
}
