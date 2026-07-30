<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;

class PaymentGatewayController extends \App\Http\Controllers\Controller
{
    /**
     * Get saved payment gateway credentials for the authenticated user.
     */
    public function show(Request $request)
    {
        $authUser = $request->get('auth_user');
        if (!$authUser || !isset($authUser['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $path = storage_path('app/gateways_' . $authUser['id'] . '.json');
        $data = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        // Never return secrets — return masked versions
        $safe = $this->mask($data);

        return response()->json(['success' => true, 'data' => $safe]);
    }

    /**
     * Save/update payment gateway credentials for the authenticated user.
     */
    public function update(Request $request)
    {
        $authUser = $request->get('auth_user');
        if (!$authUser || !isset($authUser['id'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'stripe'   => 'sometimes|array',
            'stripe.clientId'     => 'sometimes|string|max:255',
            'stripe.clientSecret' => 'sometimes|string|max:255',
            'stripe.publishableKey' => 'sometimes|string|max:255',
            'stripe.isActive'    => 'sometimes|boolean',
            'paypal'   => 'sometimes|array',
            'paypal.clientId'     => 'sometimes|string|max:255',
            'paypal.clientSecret' => 'sometimes|string|max:255',
            'paypal.isActive'     => 'sometimes|boolean',
            'instamojo' => 'sometimes|array',
            'instamojo.clientId'  => 'sometimes|string|max:255',
            'instamojo.clientSecret' => 'sometimes|string|max:255',
            'instamojo.isActive'  => 'sometimes|boolean',
        ]);

        $path = storage_path('app/gateways_' . $authUser['id'] . '.json');

        // Load existing data so we preserve masked fields
        $existing = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        // Merge new values (only non-empty strings)
        foreach (['stripe', 'paypal', 'instamojo'] as $gateway) {
            if (isset($validated[$gateway]) && is_array($validated[$gateway])) {
                foreach ($validated[$gateway] as $key => $value) {
                    if ($key === 'isActive') {
                        $existing[$gateway]['isActive'] = (bool) $value;
                    } elseif (is_string($value) && trim($value) !== '') {
                        $existing[$gateway][$key] = trim($value);
                    }
                }
            }
        }

        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT));

        return response()->json(['success' => true, 'data' => $this->mask($existing)]);
    }

    /**
     * Mask secret fields for response.
     */
    private function mask(array $data): array
    {
        $secretFields = ['clientSecret', 'publishableKey'];
        foreach (['stripe', 'paypal', 'instamojo'] as $gateway) {
            if (!isset($data[$gateway])) continue;
            foreach ($secretFields as $field) {
                if (!empty($data[$gateway][$field])) {
                    $data[$gateway][$field] = $this->maskString($data[$gateway][$field]);
                }
            }
            // Also mask clientId if it's a long key-like string
            if (!empty($data[$gateway]['clientId']) && strlen($data[$gateway]['clientId']) > 20) {
                $data[$gateway]['clientId'] = $this->maskString($data[$gateway]['clientId']);
            }
        }
        return $data;
    }

    private function maskString(string $str): string
    {
        if (strlen($str) <= 8) return '••••••••';
        return substr($str, 0, 4) . '••••••••' . substr($str, -4);
    }
}
