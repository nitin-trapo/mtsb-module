<?php

class ShopifyMultipass {
    private $encryptionKey;
    private $signatureKey;

    public function __construct($multipassSecret) {
        // Create encryption and signature keys from the Multipass secret
        $this->encryptionKey = hash_hmac('sha256', 'encryption', $multipassSecret, true);
        $this->signatureKey = hash_hmac('sha256', 'signature', $multipassSecret, true);
    }

    public function generateToken($customerData) {
        // Add required timestamp
        $customerData['created_at'] = date('c');

        // Encode the customer data as JSON
        $jsonData = json_encode($customerData);

        // Encrypt the data
        $iv = random_bytes(16); // Generate random IV
        $ciphertext = openssl_encrypt(
            $this->addPKCS7Padding($jsonData),
            'AES-128-CBC',
            $this->encryptionKey,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv
        );

        // Combine the IV and ciphertext
        $encrypted = $iv . $ciphertext;

        // Create the signature (HMAC)
        $signature = hash_hmac('sha256', $encrypted, $this->signatureKey, true);

        // Combine the signature and encrypted data
        $token = $signature . $encrypted;

        // Base64 encode the result
        return strtr(base64_encode($token), '+/', '-_');
    }

    private function addPKCS7Padding($data) {
        $blockSize = 16;
        $padSize = $blockSize - (strlen($data) % $blockSize);
        return $data . str_repeat(chr($padSize), $padSize);
    }

    public function generateUrl($customerData) {
        $token = $this->generateToken($customerData);
        return 'https://' . SHOPIFY_SHOP_DOMAIN . '/account/login/multipass/' . $token;
    }
}
