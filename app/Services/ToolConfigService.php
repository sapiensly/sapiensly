<?php

namespace App\Services;

use App\Enums\ToolType;
use Illuminate\Support\Facades\Crypt;

class ToolConfigService
{
    /**
     * Fields that should be encrypted per tool type.
     */
    private const ENCRYPTED_FIELDS = [
        'rest_api' => ['auth_config'],
        'graphql' => ['auth_config'],
        'database' => ['username', 'password'],
        'mcp' => ['auth_config'],
    ];

    /**
     * Encrypt sensitive fields in config before storage.
     */
    public function encryptConfig(ToolType $type, array $config): array
    {
        $encryptedFields = self::ENCRYPTED_FIELDS[$type->value] ?? [];

        foreach ($encryptedFields as $field) {
            if (isset($config[$field]) && ! empty($config[$field])) {
                $value = is_array($config[$field])
                    ? json_encode($config[$field])
                    : $config[$field];
                $config[$field] = Crypt::encryptString($value);
            }
        }

        return $config;
    }

    /**
     * Decrypt sensitive fields in config for use.
     */
    public function decryptConfig(ToolType $type, array $config): array
    {
        $encryptedFields = self::ENCRYPTED_FIELDS[$type->value] ?? [];

        foreach ($encryptedFields as $field) {
            if (isset($config[$field]) && ! empty($config[$field])) {
                try {
                    $decrypted = Crypt::decryptString($config[$field]);
                    $config[$field] = json_decode($decrypted, true) ?? $decrypted;
                } catch (\Exception $e) {
                    // Value might not be encrypted (legacy data)
                }
            }
        }

        return $config;
    }

    /**
     * Mask sensitive fields for frontend display.
     */
    public function maskSensitiveFields(ToolType $type, array $config): array
    {
        $encryptedFields = self::ENCRYPTED_FIELDS[$type->value] ?? [];

        foreach ($encryptedFields as $field) {
            if (isset($config[$field]) && ! empty($config[$field])) {
                $config[$field.'_is_set'] = true;
                unset($config[$field]);
            }
        }

        return $config;
    }

    /**
     * Check if a tool type has sensitive fields.
     */
    public function hasSensitiveFields(ToolType $type): bool
    {
        return ! empty(self::ENCRYPTED_FIELDS[$type->value] ?? []);
    }
}
